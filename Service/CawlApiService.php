<?php

declare(strict_types=1);

namespace CawlPayment\Service;

use CawlPayment\CawlPayment;
use CawlPayment\Model\CawlTransaction;
use CawlPayment\Model\CawlTransactionQuery;
use OnlinePayments\Sdk\Client;
use OnlinePayments\Sdk\Communicator;
use OnlinePayments\Sdk\CommunicatorConfiguration;
use OnlinePayments\Sdk\DefaultConnection;
use OnlinePayments\Sdk\Domain\AmountOfMoney;
use OnlinePayments\Sdk\Domain\ContactDetails;
use OnlinePayments\Sdk\Domain\CreateHostedCheckoutRequest;
use OnlinePayments\Sdk\Domain\Customer;
use OnlinePayments\Sdk\Domain\HostedCheckoutSpecificInput;
use OnlinePayments\Sdk\Domain\Order as SdkOrder;
use OnlinePayments\Sdk\Domain\OrderReferences;
use OnlinePayments\Sdk\Domain\Address;
use OnlinePayments\Sdk\Domain\PaymentProductFiltersHostedCheckout;
use OnlinePayments\Sdk\Domain\PaymentProductFilter;
use Thelia\Log\Tlog;
use Thelia\Model\Order;

/**
 * Service for interacting with CAWL Solutions API using official SDK
 */
class CawlApiService
{
    private ?Tlog $logger = null;
    private ?Client $client = null;

    public function __construct(
        private readonly CredentialsEncryptionService $encryptionService
    ) {
    }

    /**
     * Get logger instance
     */
    private function getLogger(): Tlog
    {
        if ($this->logger === null) {
            $this->logger = Tlog::getNewInstance();
            $this->logger->setDestinations('\\Thelia\\Log\\Destination\\TlogDestinationFile');
            $this->logger->setConfig('\\Thelia\\Log\\Destination\\TlogDestinationFile', '0', THELIA_LOG_DIR . 'cawlpayment.log');
        }
        return $this->logger;
    }

    /**
     * Log message if logging is enabled
     */
    private function log(string $message, string $level = 'info'): void
    {
        if ($this->isLoggingEnabled()) {
            $this->getLogger()->$level('[CawlPayment] ' . $message);
        }
    }

    private function isLoggingEnabled(): bool
    {
        return (bool) CawlPayment::getConfigValue('enable_logging', '1');
    }

    private function isProductionMode(): bool
    {
        return $this->getActiveEnvironment() === CawlPayment::ENV_PRODUCTION;
    }

    public function getActiveEnvironment(): string
    {
        return CawlPayment::getConfigValue('environment', CawlPayment::ENV_TEST);
    }

    public function getApiUrl(): string
    {
        return $this->isProductionMode() ? CawlPayment::API_URL_PROD : CawlPayment::API_URL_TEST;
    }

    private function getActiveApiKey(): string
    {
        $suffix = $this->isProductionMode() ? '_prod' : '_test';
        return $this->getDecryptedConfigValue('api_key' . $suffix, '');
    }

    private function getActiveApiSecret(): string
    {
        $suffix = $this->isProductionMode() ? '_prod' : '_test';
        return $this->getDecryptedConfigValue('api_secret' . $suffix, '');
    }

    private function getActiveWebhookKey(): string
    {
        $suffix = $this->isProductionMode() ? '_prod' : '_test';
        return $this->getDecryptedConfigValue('webhook_key' . $suffix, '');
    }

    private function getActiveWebhookSecret(): string
    {
        $suffix = $this->isProductionMode() ? '_prod' : '_test';
        return $this->getDecryptedConfigValue('webhook_secret' . $suffix, '');
    }

    /**
     * Get a decrypted config value for sensitive credentials
     */
    private function getDecryptedConfigValue(string $key, string $default = ''): string
    {
        $value = CawlPayment::getConfigValue($key, $default);

        if (empty($value)) {
            return $default;
        }

        if ($this->encryptionService->isSensitiveKey($key)) {
            try {
                $value = $this->encryptionService->decrypt($value);
            } catch (\Exception $e) {
                Tlog::getInstance()->warning(
                    '[CawlPayment] Could not decrypt config "' . $key . '", treating as plaintext: ' . $e->getMessage()
                );
            }
        }

        return $value;
    }

    /**
     * Get SDK Client instance
     */
    private function getClient(): Client
    {
        if ($this->client === null) {
            $apiKey = $this->getActiveApiKey();
            $apiSecret = $this->getActiveApiSecret();

            // Determine endpoint based on environment
            $endpoint = $this->isProductionMode()
                ? 'https://payment.direct.worldline-solutions.com'
                : 'https://payment.preprod.direct.worldline-solutions.com';

            $communicatorConfig = new CommunicatorConfiguration(
                $apiKey,
                $apiSecret,
                $endpoint,
                'CawlPayment/1.0.0'
            );

            $connection = new DefaultConnection();
            $communicator = new Communicator($connection, $communicatorConfig);
            $this->client = new Client($communicator);
        }

        return $this->client;
    }

    /**
     * Get merchant ID (PSPID)
     */
    private function getMerchantId(): string
    {
        return CawlPayment::getConfigValue('pspid', '');
    }

    /**
     * Test API connection - Get available payment products
     */
    public function testConnection(): array
    {
        $this->log("Testing API connection");

        try {
            $client = $this->getClient();
            $merchantId = $this->getMerchantId();

            // Test by getting test connection endpoint
            $response = $client->merchant($merchantId)->services()->testConnection();

            $this->log("Connection test successful: " . ($response->getResult() ?? 'OK'));

            return [
                'success' => true,
                'environment' => $this->getActiveEnvironment(),
                'endpoint' => $this->getApiUrl(),
                'merchant_id' => $merchantId,
                'result' => $response->getResult(),
            ];
        } catch (\Exception $e) {
            $this->log("Connection test failed: " . $e->getMessage(), 'error');

            return [
                'success' => false,
                'environment' => $this->getActiveEnvironment(),
                'endpoint' => $this->getApiUrl(),
                'merchant_id' => $this->getMerchantId(),
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
            ];
        }
    }

    /**
     * Get available payment products with caching
     * Returns cached data if available and not expired
     */
    public function getPaymentProductsCached(int $amount = 10000, string $currency = 'EUR', string $countryCode = 'FR', int $cacheTime = 3600): array
    {
        $cacheKey = 'cawl_products_' . md5($amount . $currency . $countryCode);
        $cacheFile = THELIA_CACHE_DIR . $cacheKey . '.json';

        // Check if cache exists and is valid
        if (file_exists($cacheFile)) {
            $cacheData = json_decode(file_get_contents($cacheFile), true);
            if ($cacheData && isset($cacheData['timestamp']) && (time() - $cacheData['timestamp']) < $cacheTime) {
                $this->log("Returning cached payment products");
                return $cacheData['data'];
            }
        }

        // Fetch fresh data
        $result = $this->getPaymentProducts($amount, $currency, $countryCode);

        // Cache the result if successful
        if ($result['success']) {
            $cacheData = [
                'timestamp' => time(),
                'data' => $result,
            ];
            file_put_contents($cacheFile, json_encode($cacheData), LOCK_EX);
        }

        return $result;
    }

    /**
     * Get available payment products
     */
    public function getPaymentProducts(int $amount = 10000, string $currency = 'EUR', string $countryCode = 'FR'): array
    {
        $this->log("Getting available payment products for {$amount} {$currency} in {$countryCode}");

        try {
            $client = $this->getClient();
            $merchantId = $this->getMerchantId();

            $queryParams = new \OnlinePayments\Sdk\Merchant\Products\GetPaymentProductsParams();
            $queryParams->setAmount($amount);
            $queryParams->setCurrencyCode($currency);
            $queryParams->setCountryCode($countryCode);

            $response = $client->merchant($merchantId)->products()->getPaymentProducts($queryParams);

            $products = [];
            foreach ($response->getPaymentProducts() ?? [] as $product) {
                $products[] = [
                    'id' => $product->getId(),
                    'displayHints' => [
                        'displayOrder' => $product->getDisplayHints()?->getDisplayOrder(),
                        'label' => $product->getDisplayHints()?->getLabel(),
                        'logo' => $product->getDisplayHints()?->getLogo(),
                    ],
                    'paymentMethod' => $product->getPaymentMethod(),
                    'paymentProductGroup' => $product->getPaymentProductGroup(),
                ];
            }

            $this->log("Found " . count($products) . " payment products");

            return [
                'success' => true,
                'products' => $products,
                'count' => count($products),
            ];
        } catch (\Exception $e) {
            $this->log("Failed to get payment products: " . $e->getMessage(), 'error');

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a Hosted Checkout session
     */
    public function createHostedCheckout(Order $order, string $paymentMethod, string $returnUrl, string $webhookUrl): array
    {
        $this->log("Creating hosted checkout for order #{$order->getId()}, method: {$paymentMethod}");

        try {
            $client = $this->getClient();
            $merchantId = $this->getMerchantId();

            // Calculate amount in cents
            $amount = (int) round($order->getTotalAmount() * 100);
            $currency = $order->getCurrency()->getCode();

            // Get customer info
            $customer = $order->getCustomer();
            $invoiceAddress = $order->getOrderAddressRelatedByInvoiceOrderAddressId();

            // Build AmountOfMoney
            $amountOfMoney = new AmountOfMoney();
            $amountOfMoney->setAmount($amount);
            $amountOfMoney->setCurrencyCode($currency);

            // Build billing address
            $billingAddress = new Address();
            $billingAddress->setCity($invoiceAddress->getCity());
            $billingAddress->setCountryCode($invoiceAddress->getCountry()->getIsoalpha2());
            $billingAddress->setStreet($invoiceAddress->getAddress1());
            $billingAddress->setZip($invoiceAddress->getZipcode());

            // Build contact details
            $contactDetails = new ContactDetails();
            $contactDetails->setEmailAddress($customer->getEmail());

            // Build customer
            $sdkCustomer = new Customer();
            $sdkCustomer->setMerchantCustomerId((string) $customer->getId());
            $sdkCustomer->setBillingAddress($billingAddress);
            $sdkCustomer->setContactDetails($contactDetails);

            // Build order references
            $references = new OrderReferences();
            $references->setMerchantReference($order->getRef());

            // Build order
            $sdkOrder = new SdkOrder();
            $sdkOrder->setAmountOfMoney($amountOfMoney);
            $sdkOrder->setCustomer($sdkCustomer);
            $sdkOrder->setReferences($references);

            // Build hosted checkout specific input
            $hostedCheckoutInput = new HostedCheckoutSpecificInput();
            $hostedCheckoutInput->setReturnUrl($returnUrl);
            $hostedCheckoutInput->setLocale($this->getLocale());
            $hostedCheckoutInput->setShowResultPage(false);

            // Add payment method filter if specified
            if (!empty($paymentMethod) && isset(CawlPayment::PAYMENT_METHODS[$paymentMethod])) {
                $productId = CawlPayment::PAYMENT_METHODS[$paymentMethod]['id'];

                $filter = new PaymentProductFilter();
                $filter->setProducts([$productId]);

                $productFilters = new PaymentProductFiltersHostedCheckout();
                $productFilters->setRestrictTo($filter);

                $hostedCheckoutInput->setPaymentProductFilters($productFilters);
            }

            // Build request
            $request = new CreateHostedCheckoutRequest();
            $request->setOrder($sdkOrder);
            $request->setHostedCheckoutSpecificInput($hostedCheckoutInput);

            // Make API call
            $response = $client->merchant($merchantId)->hostedCheckout()->createHostedCheckout($request);

            $hostedCheckoutId = $response->getHostedCheckoutId();
            $returnMac = $response->getRETURNMAC();
            $redirectUrl = $response->getRedirectUrl();

            // Create transaction record
            $transaction = new CawlTransaction();
            $transaction->setOrderId($order->getId());
            $transaction->setPaymentMethod($paymentMethod);
            $transaction->setAmount($order->getTotalAmount());
            $transaction->setCurrency($currency);
            $transaction->setStatus('pending');
            $transaction->setHostedCheckoutId($hostedCheckoutId);
            $transaction->setRawRequest(json_encode($request->toObject()));
            $transaction->setRawResponse(json_encode([
                'hostedCheckoutId' => $hostedCheckoutId,
                'RETURNMAC' => $returnMac,
                'redirectUrl' => $redirectUrl,
            ]));
            $transaction->save();

            $this->log("Hosted checkout created: {$hostedCheckoutId}");

            return [
                'success' => true,
                'hostedCheckoutId' => $hostedCheckoutId,
                'RETURNMAC' => $returnMac,
                'redirectUrl' => $redirectUrl,
            ];
        } catch (\Exception $e) {
            $this->log("Failed to create hosted checkout: " . $e->getMessage(), 'error');

            // Create failed transaction record
            $transaction = new CawlTransaction();
            $transaction->setOrderId($order->getId());
            $transaction->setPaymentMethod($paymentMethod);
            $transaction->setAmount($order->getTotalAmount());
            $transaction->setCurrency($order->getCurrency()->getCode());
            $transaction->setStatus('error');
            $transaction->setErrorMessage($e->getMessage());
            $transaction->save();

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get hosted checkout status
     */
    public function getHostedCheckoutStatus(string $hostedCheckoutId): array
    {
        $this->log("Getting hosted checkout status: {$hostedCheckoutId}");

        try {
            $client = $this->getClient();
            $merchantId = $this->getMerchantId();

            $response = $client->merchant($merchantId)->hostedCheckout()->getHostedCheckout($hostedCheckoutId);

            $status = $response->getStatus();
            $createdPaymentOutput = $response->getCreatedPaymentOutput();

            $paymentId = null;
            $paymentStatus = null;
            $statusCode = null;

            if ($createdPaymentOutput && $createdPaymentOutput->getPayment()) {
                $payment = $createdPaymentOutput->getPayment();
                $paymentId = $payment->getId();
                $paymentStatus = $payment->getStatus();
                $statusCode = $payment->getStatusOutput()?->getStatusCode();
            }

            // Update transaction record
            $transaction = CawlTransactionQuery::create()
                ->filterByHostedCheckoutId($hostedCheckoutId)
                ->findOne();

            if ($transaction) {
                $transaction->setStatus($this->mapCawlStatus($status));
                if ($paymentId) {
                    $transaction->setTransactionRef($paymentId);
                }
                if ($statusCode) {
                    $transaction->setStatusCode($statusCode);
                }
                $transaction->setRawResponse(json_encode([
                    'status' => $status,
                    'paymentId' => $paymentId,
                    'paymentStatus' => $paymentStatus,
                    'statusCode' => $statusCode,
                ]));
                $transaction->save();
            }

            $this->log("Hosted checkout status: {$status}");

            return [
                'success' => true,
                'status' => $status,
                'paymentId' => $paymentId,
                'paymentStatus' => $paymentStatus,
                'statusCode' => $statusCode,
                'isPaid' => $this->isSuccessStatus($status),
            ];
        } catch (\Exception $e) {
            $this->log("Failed to get hosted checkout status: " . $e->getMessage(), 'error');

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get payment status from CAWL
     */
    public function getPaymentStatus(string $paymentId): array
    {
        $this->log("Getting payment status: {$paymentId}");

        try {
            $client = $this->getClient();
            $merchantId = $this->getMerchantId();

            $response = $client->merchant($merchantId)->payments()->getPaymentDetails($paymentId);

            $status = $response->getStatus();
            $statusCode = $response->getStatusOutput()?->getStatusCode();

            $this->log("Payment status: {$status}");

            return [
                'success' => true,
                'paymentId' => $response->getId(),
                'status' => $status,
                'statusCode' => $statusCode,
                'isPaid' => $this->isSuccessStatus($status),
            ];
        } catch (\Exception $e) {
            $this->log("Failed to get payment status: " . $e->getMessage(), 'error');

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a test hosted checkout (for testing purposes)
     */
    public function createTestHostedCheckout(int $amount = 1000, string $currency = 'EUR'): array
    {
        $this->log("Creating test hosted checkout: {$amount} {$currency}");

        try {
            $client = $this->getClient();
            $merchantId = $this->getMerchantId();

            // Build AmountOfMoney
            $amountOfMoney = new AmountOfMoney();
            $amountOfMoney->setAmount($amount);
            $amountOfMoney->setCurrencyCode($currency);

            // Build order references
            $references = new OrderReferences();
            $references->setMerchantReference('TEST-' . time());

            // Build order
            $sdkOrder = new SdkOrder();
            $sdkOrder->setAmountOfMoney($amountOfMoney);
            $sdkOrder->setReferences($references);

            // Build hosted checkout specific input
            $baseUrl = \Thelia\Model\ConfigQuery::read('url_site', '');
            $returnUrl = rtrim($baseUrl, '/') . '/admin/cawlpayment/test-return';

            $hostedCheckoutInput = new HostedCheckoutSpecificInput();
            $hostedCheckoutInput->setReturnUrl($returnUrl);
            $hostedCheckoutInput->setLocale($this->getLocale());
            $hostedCheckoutInput->setShowResultPage(true);

            // Build request
            $request = new CreateHostedCheckoutRequest();
            $request->setOrder($sdkOrder);
            $request->setHostedCheckoutSpecificInput($hostedCheckoutInput);

            // Make API call
            $response = $client->merchant($merchantId)->hostedCheckout()->createHostedCheckout($request);

            $hostedCheckoutId = $response->getHostedCheckoutId();
            $returnMac = $response->getRETURNMAC();
            $redirectUrl = $response->getRedirectUrl();

            $this->log("Test hosted checkout created: {$hostedCheckoutId}");

            return [
                'success' => true,
                'hostedCheckoutId' => $hostedCheckoutId,
                'RETURNMAC' => $returnMac,
                'redirectUrl' => $redirectUrl,
                'checkoutUrl' => $this->getCheckoutUrl($hostedCheckoutId, $returnMac),
            ];
        } catch (\Exception $e) {
            $this->log("Failed to create test hosted checkout: " . $e->getMessage(), 'error');

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Process webhook notification
     */
    public function processWebhook(array $payload, string $signature, string $rawBody = ''): array
    {
        $this->log("Processing webhook notification");

        // Verify signature using raw body to preserve original encoding
        if (!$this->verifyWebhookSignature($rawBody ?: json_encode($payload), $signature)) {
            $this->log("Invalid webhook signature", 'error');
            return [
                'success' => false,
                'error' => 'Invalid signature',
            ];
        }

        // Extract payment info
        $paymentId = $payload['payment']['id'] ?? null;
        $status = $payload['payment']['status'] ?? null;
        $statusCode = $payload['payment']['statusOutput']['statusCode'] ?? null;
        $merchantReference = $payload['payment']['paymentOutput']['references']['merchantReference'] ?? null;

        if (!$paymentId || !$merchantReference) {
            $this->log("Missing payment ID or merchant reference", 'error');
            return [
                'success' => false,
                'error' => 'Missing required fields',
            ];
        }

        // Find transaction by order reference
        $transaction = CawlTransactionQuery::create()
            ->useOrderQuery()
                ->filterByRef($merchantReference)
            ->endUse()
            ->findOne();

        if (!$transaction) {
            $this->log("Transaction not found for reference: {$merchantReference}", 'error');
            return [
                'success' => false,
                'error' => 'Transaction not found',
            ];
        }

        // Update transaction
        $transaction->setTransactionRef($paymentId);
        $transaction->setStatus($this->mapCawlStatus($status));
        $transaction->setStatusCode($statusCode);
        $transaction->setRawResponse(json_encode($payload));
        $transaction->save();

        $this->log("Webhook processed: reference {$merchantReference}, status: {$status}");

        return [
            'success' => true,
            'order_id' => $transaction->getOrderId(),
            'status' => $this->mapCawlStatus($status),
            'is_paid' => $this->isSuccessStatus($status),
        ];
    }

    /**
     * Verify webhook signature
     */
    private function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $webhookSecret = $this->getActiveWebhookSecret();

        // SECURITY: Reject webhooks if no secret is configured in production
        if (empty($webhookSecret)) {
            if ($this->isProductionMode()) {
                $this->log("SECURITY: No webhook secret configured in production - rejecting webhook", 'error');
                return false;
            }
            // Allow in test mode only with a warning
            $this->log("WARNING: No webhook secret configured in test mode - skipping signature verification", 'warning');
            return true;
        }

        if (empty($signature)) {
            $this->log("SECURITY: No signature provided in webhook request - rejecting", 'error');
            return false;
        }

        // CAWL uses HMAC-SHA256 with the raw HTTP body (not re-encoded JSON)
        $expectedSignature = base64_encode(hash_hmac('sha256', $rawBody, $webhookSecret, true));

        // Use hash_equals to prevent timing attacks
        $isValid = hash_equals($expectedSignature, $signature);

        if (!$isValid) {
            $this->log("SECURITY: Invalid webhook signature - rejecting", 'error');
        }

        return $isValid;
    }

    /**
     * Map CAWL status to internal status
     */
    private function mapCawlStatus(string $cawlStatus): string
    {
        $statusMap = [
            'PAYMENT_CREATED' => 'pending',
            'IN_PROGRESS' => 'pending',
            'PENDING_PAYMENT' => 'pending',
            'PENDING_COMPLETION' => 'pending',
            'PENDING_CAPTURE' => 'authorized',
            'AUTHORIZATION_REQUESTED' => 'pending',
            'CAPTURE_REQUESTED' => 'pending',
            'CAPTURED' => 'captured',
            'PAID' => 'captured',
            'CANCELLED' => 'cancelled',
            'REJECTED' => 'rejected',
            'REFUNDED' => 'refunded',
            'CHARGEBACKED' => 'chargebacked',
        ];

        return $statusMap[$cawlStatus] ?? strtolower($cawlStatus);
    }

    /**
     * Check if status indicates successful payment
     */
    public function isSuccessStatus(string $status): bool
    {
        return in_array($status, ['CAPTURED', 'PAID', 'PENDING_CAPTURE']);
    }

    /**
     * Get checkout page URL
     */
    public function getCheckoutUrl(string $hostedCheckoutId, string $returnMac): string
    {
        $baseUrl = $this->isProductionMode()
            ? 'https://payment.direct.worldline-solutions.com'
            : 'https://payment.preprod.direct.worldline-solutions.com';

        return $baseUrl . '/hostedcheckout/' . $hostedCheckoutId . '/' . $returnMac;
    }

    /**
     * Get current locale
     */
    private function getLocale(): string
    {
        try {
            $lang = \Thelia\Model\LangQuery::create()
                ->filterByByDefault(1)
                ->findOne();

            if ($lang) {
                return str_replace('_', '-', $lang->getLocale());
            }
        } catch (\Exception $e) {
            // Fallback
        }

        return 'fr-FR';
    }

    /**
     * Get API configuration summary (for debug purposes)
     */
    public function getConfigurationSummary(): array
    {
        return [
            'environment' => $this->getActiveEnvironment(),
            'endpoint' => $this->getApiUrl(),
            'merchant_id' => $this->getMerchantId(),
            'api_key_configured' => !empty($this->getActiveApiKey()),
            'api_secret_configured' => !empty($this->getActiveApiSecret()),
            'logging_enabled' => $this->isLoggingEnabled(),
            'enabled_methods' => array_keys($this->getEnabledPaymentMethods()),
        ];
    }

    /**
     * Get list of enabled payment methods (from module config)
     */
    public function getEnabledPaymentMethods(): array
    {
        $enabled = [];
        $enabledMethods = CawlPayment::getConfigValue('enabled_methods', '');

        if (empty($enabledMethods)) {
            return $enabled;
        }

        $methodCodes = explode(',', $enabledMethods);

        foreach ($methodCodes as $code) {
            $code = trim($code);

            if (isset(CawlPayment::PAYMENT_METHODS[$code])) {
                $enabled[$code] = CawlPayment::PAYMENT_METHODS[$code];
            } elseif (strpos($code, 'product_') === 0) {
                $productId = (int) substr($code, 8);
                if ($productId > 0) {
                    $enabled[$code] = [
                        'id' => $productId,
                        'name' => 'Payment ' . $productId,
                        'category' => 'api',
                        'icon' => '',
                    ];
                }
            }
        }

        return $enabled;
    }
}
