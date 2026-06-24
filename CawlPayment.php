<?php

declare(strict_types=1);

namespace CawlPayment;

use CawlPayment\EventListeners\PaymentOptionsListener;
use CawlPayment\Service\CawlApiService;
use CawlPayment\Service\CredentialsEncryptionService;
use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Model\Order;
use Thelia\Module\AbstractPaymentModule;
use Thelia\Install\Database;
use Thelia\Log\Tlog;
use Thelia\Tools\URL;

/**
 * CawlPayment - CAWL Solutions Payment Module for Thelia 3
 *
 * Integrates CAWL Solutions payment gateway with support for 30+ payment methods
 * including cards, wallets, and buy-now-pay-later options.
 */
class CawlPayment extends AbstractPaymentModule
{
    public const DOMAIN_NAME = 'cawlpayment';
    public const MODULE_CODE = 'CawlPayment';

    /** Environment constants */
    public const ENV_TEST = 'test';
    public const ENV_PRODUCTION = 'production';

    /** API Endpoints */
    public const API_URL_TEST = 'https://payment.preprod.direct.worldline-solutions.com/v2';
    public const API_URL_PROD = 'https://payment.direct.worldline-solutions.com/v2';

    /** Supported payment methods with their CAWL product IDs */
    public const PAYMENT_METHODS = [
        // Cards
        'visa' => ['id' => 1, 'name' => 'Visa', 'category' => 'cards', 'icon' => 'visa.svg'],
        'mastercard' => ['id' => 3, 'name' => 'Mastercard', 'category' => 'cards', 'icon' => 'mastercard.svg'],
        'cb' => ['id' => 130, 'name' => 'Carte Bancaire', 'category' => 'cards', 'icon' => 'cb.svg'],
        'amex' => ['id' => 2, 'name' => 'American Express', 'category' => 'cards', 'icon' => 'amex.svg'],
        'maestro' => ['id' => 117, 'name' => 'Maestro', 'category' => 'cards', 'icon' => 'maestro.svg'],

        // Wallets
        'applepay' => ['id' => 302, 'name' => 'Apple Pay', 'category' => 'wallets', 'icon' => 'applepay.svg'],
        'googlepay' => ['id' => 320, 'name' => 'Google Pay', 'category' => 'wallets', 'icon' => 'googlepay.svg'],
        'paypal' => ['id' => 840, 'name' => 'PayPal', 'category' => 'wallets', 'icon' => 'paypal.svg'],
        'wechatpay' => ['id' => 863, 'name' => 'WeChat Pay', 'category' => 'wallets', 'icon' => 'wechatpay.svg'],

        // Bank transfers
        'ideal' => ['id' => 809, 'name' => 'iDEAL', 'category' => 'banktransfer', 'icon' => 'ideal.svg'],
        'bancontact' => ['id' => 3012, 'name' => 'Bancontact', 'category' => 'banktransfer', 'icon' => 'bancontact.svg'],
        'przelewy24' => ['id' => 3124, 'name' => 'Przelewy24', 'category' => 'banktransfer', 'icon' => 'przelewy24.svg'],
        'eps' => ['id' => 5406, 'name' => 'EPS', 'category' => 'banktransfer', 'icon' => 'eps.svg'],
        'giropay' => ['id' => 5408, 'name' => 'Giropay', 'category' => 'banktransfer', 'icon' => 'giropay.svg'],

        // Buy now pay later
        'klarna_paynow' => ['id' => 3301, 'name' => 'Klarna Pay Now', 'category' => 'bnpl', 'icon' => 'klarna.svg'],
        'klarna_paylater' => ['id' => 3302, 'name' => 'Klarna Pay Later', 'category' => 'bnpl', 'icon' => 'klarna.svg'],
        'klarna_sliceit' => ['id' => 3303, 'name' => 'Klarna Slice It', 'category' => 'bnpl', 'icon' => 'klarna.svg'],
        'oney3x' => ['id' => 5110, 'name' => 'Oney 3x', 'category' => 'bnpl', 'icon' => 'oney.svg'],
        'oney4x' => ['id' => 5111, 'name' => 'Oney 4x', 'category' => 'bnpl', 'icon' => 'oney.svg'],

        // Meal vouchers
        'edenred' => ['id' => 5765, 'name' => 'Edenred', 'category' => 'vouchers', 'icon' => 'edenred.svg'],
        'sodexo' => ['id' => 5766, 'name' => 'Sodexo', 'category' => 'vouchers', 'icon' => 'sodexo.svg'],
        'updejeuner' => ['id' => 5767, 'name' => 'Up Déjeuner', 'category' => 'vouchers', 'icon' => 'updejeuner.svg'],
    ];

    /** Payment method categories */
    public const CATEGORIES = [
        'cards' => 'Cartes bancaires',
        'wallets' => 'Portefeuilles numériques',
        'banktransfer' => 'Virements bancaires',
        'bnpl' => 'Paiement fractionné',
        'vouchers' => 'Titres restaurant',
    ];

    /** @var CredentialsEncryptionService|null Singleton instance for credential decryption */
    private static ?CredentialsEncryptionService $encryptionService = null;

    /**
     * Configure services for dependency injection.
     *
     * Note: uses __DIR__ for exclusion because the module is in local/modules,
     * not in vendor/thelia/modules (THELIA_MODULE_DIR).
     * Hooks and loops are declared in config.xml — excluded from the generic load.
     */
    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Tests/*',
                __DIR__.'/Controller/*',
                __DIR__.'/Hook/*',
                __DIR__.'/Loop/*',
                __DIR__.'/Form/*',
                __DIR__.'/vendor/*',
            ])
            ->autowire(true)
            ->autoconfigure(true);

        $servicesConfigurator->load(self::getModuleCode().'\\Controller\\', __DIR__.'/Controller/')
            ->autowire(true)
            ->autoconfigure(true)
            ->public()
            ->tag('controller.service_arguments');

        // Must be public: accessed via $this->getContainer()->get() in pay()
        $servicesConfigurator->set(CawlApiService::class)
            ->autowire(true)
            ->public();

        // Only register when the OpenApi module is present
        if (class_exists('OpenApi\Events\OpenApiEvents')) {
            $servicesConfigurator->set(PaymentOptionsListener::class)
                ->autowire(true)
                ->tag('kernel.event_subscriber');
        }
    }

    /**
     * Called when customer pays with this module
     * Creates a hosted checkout on CAWL and redirects customer to payment page
     */
    public function pay(Order $order): ?Response
    {
        try {
            /** @var CawlApiService $apiService */
            $apiService = $this->getContainer()->get(CawlApiService::class);

            // Build return URL - success callback
            $baseUrl = \Thelia\Model\ConfigQuery::read('url_site', '');
            $returnUrl = rtrim($baseUrl, '/') . '/cawlpayment/success?order_id=' . $order->getId();
            $webhookUrl = rtrim($baseUrl, '/') . '/cawlpayment/webhook';

            // Create hosted checkout (without specific payment method - user will choose on CAWL page)
            $result = $apiService->createHostedCheckout($order, '', $returnUrl, $webhookUrl);

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Failed to create payment session');
            }

            // Redirect to CAWL hosted checkout page
            $checkoutUrl = $result['redirectUrl'] ?? $apiService->getCheckoutUrl(
                $result['hostedCheckoutId'],
                $result['RETURNMAC']
            );

            return new RedirectResponse($checkoutUrl);

        } catch (\Exception $e) {
            // Log error with full details
            Tlog::getInstance()->error('[CawlPayment] Payment error: ' . $e->getMessage());

            // Redirect to failure page with generic message (don't expose internal error details)
            return new RedirectResponse(
                URL::getInstance()->absoluteUrl('/order/failed/' . $order->getId(), ['error' => urlencode('Payment initialization failed')])
            );
        }
    }

    /**
     * Check if payment method is valid for current cart
     */
    public function isValidPayment(): bool
    {
        $pspid = self::getConfigValue('pspid');
        $apiKey = $this->getActiveApiKey();
        $apiSecret = $this->getActiveApiSecret();
        $enabledMethods = $this->getEnabledPaymentMethods();

        $isConfigured = !empty($pspid) && !empty($apiKey) && !empty($apiSecret);

        if (!$isConfigured) {
            return false;
        }

        // Check at least one payment method is enabled
        if (count($enabledMethods) === 0) {
            return false;
        }

        // Check minimum/maximum amount (only if cart is available)
        try {
            $cartAmount = $this->getCurrentOrderTotalAmount();

            // Check minimum amount
            $minAmount = (float) self::getConfigValue('min_amount', 0);
            if ($minAmount > 0 && $cartAmount < $minAmount) {
                return false;
            }

            // Check maximum amount
            $maxAmount = (float) self::getConfigValue('max_amount', 0);
            if ($maxAmount > 0 && $cartAmount > $maxAmount) {
                return false;
            }
        } catch (\Exception $e) {
            // Cart/session not available - skip amount validation
            // This can happen when called from API context
        }

        return true;
    }

    /**
     * Post activation - create database tables
     *
     * Uses Thelia's standard is_initialized flag pattern to prevent
     * duplicate database insertions during module re-activation.
     */
    public function postActivation(ConnectionInterface $con = null): void
    {
        // Check if module has already been initialized using Thelia's standard pattern
        if (self::getConfigValue('is_initialized', false)) {
            Tlog::getInstance()->info('[CawlPayment] Module already initialized, skipping database setup');
            return;
        }

        try {
            $database = new Database($con);
            $database->insertSql(null, [__DIR__ . '/Config/TheliaMain.sql']);

            // Mark module as initialized
            self::setConfigValue('is_initialized', true);

            Tlog::getInstance()->info('[CawlPayment] Module successfully initialized, database tables created');

        } catch (\Exception $e) {
            // Log the error with full details for debugging
            Tlog::getInstance()->error(
                '[CawlPayment] Failed to initialize module: ' . $e->getMessage()
            );

            // Re-throw to signal activation failure to Thelia
            throw new \RuntimeException(
                'CawlPayment module initialization failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get list of enabled payment methods
     * Supports both legacy format (visa, mastercard) and new API format (product_1, product_3)
     */
    public function getEnabledPaymentMethods(): array
    {
        $enabled = [];
        $enabledMethods = self::getConfigValue('enabled_methods', '');

        if (empty($enabledMethods)) {
            return $enabled;
        }

        $methodCodes = explode(',', $enabledMethods);

        foreach ($methodCodes as $code) {
            $code = trim($code);

            // Check legacy format (visa, mastercard, etc.)
            if (isset(self::PAYMENT_METHODS[$code])) {
                $enabled[$code] = self::PAYMENT_METHODS[$code];
            }
            // Check new API format (product_1, product_3, etc.)
            elseif (strpos($code, 'product_') === 0) {
                $productId = (int) substr($code, 8);
                if ($productId > 0) {
                    $enabled[$code] = [
                        'id' => $productId,
                        'name' => $this->getProductNameById($productId),
                        'category' => 'api',
                        'icon' => '', // Icon will be fetched from API
                    ];
                }
            }
        }

        return $enabled;
    }

    /**
     * Get product name by ID (from static mapping or generic name)
     */
    private function getProductNameById(int $productId): string
    {
        // Map common product IDs to names (from CAWL/Worldline API)
        $productNames = [
            1 => 'Visa',
            2 => 'American Express',
            3 => 'Mastercard',
            117 => 'Maestro',
            125 => 'JCB',
            128 => 'Discover',
            130 => 'Carte Bancaire',
            132 => 'Diners Club',
            302 => 'Apple Pay',
            320 => 'Google Pay',
            809 => 'iDEAL',
            840 => 'PayPal',
            861 => 'Alipay',
            863 => 'WeChat Pay',
            3012 => 'Bancontact',
            3112 => 'Illicado',
            3124 => 'Przelewy24',
            3301 => 'Klarna Pay Now',
            3302 => 'Klarna Pay Later',
            3303 => 'Klarna Slice It',
            5001 => 'Cpay',
            5110 => 'Oney 3x',
            5111 => 'Oney 4x',
            5125 => 'Bizum',
            5402 => 'Mealvouchers',
            5404 => 'Intersolve',
            5405 => 'Edenred',
            5408 => 'Giropay',
            5500 => 'Multibanco',
            5600 => 'TWINT',
            5700 => 'EPS',
            5771 => 'PostFinance Card',
            5772 => 'PostFinance E-Finance',
        ];

        return $productNames[$productId] ?? 'Payment ' . $productId;
    }

    /**
     * Check if a specific payment method is enabled
     */
    public function isPaymentMethodEnabled(string $methodCode): bool
    {
        $enabledMethods = $this->getEnabledPaymentMethods();
        return isset($enabledMethods[$methodCode]);
    }

    /**
     * Get active environment
     */
    public function getActiveEnvironment(): string
    {
        return self::getConfigValue('environment', self::ENV_TEST);
    }

    /**
     * Check if in production mode
     */
    public function isProductionMode(): bool
    {
        return $this->getActiveEnvironment() === self::ENV_PRODUCTION;
    }

    /**
     * Get API URL based on environment
     */
    public function getApiUrl(): string
    {
        return $this->isProductionMode() ? self::API_URL_PROD : self::API_URL_TEST;
    }

    /**
     * Get active API Key based on environment (decrypted)
     */
    public function getActiveApiKey(): string
    {
        $suffix = $this->isProductionMode() ? '_prod' : '_test';
        return $this->getDecryptedConfigValue('api_key' . $suffix, '');
    }

    /**
     * Get active API Secret based on environment (decrypted)
     */
    public function getActiveApiSecret(): string
    {
        $suffix = $this->isProductionMode() ? '_prod' : '_test';
        return $this->getDecryptedConfigValue('api_secret' . $suffix, '');
    }

    /**
     * Get PSPID (Merchant ID)
     */
    public function getPspid(): string
    {
        return self::getConfigValue('pspid', '');
    }

    /**
     * Get active Webhook Key based on environment (decrypted)
     */
    public function getActiveWebhookKey(): string
    {
        $suffix = $this->isProductionMode() ? '_prod' : '_test';
        return $this->getDecryptedConfigValue('webhook_key' . $suffix, '');
    }

    /**
     * Get active Webhook Secret based on environment (decrypted)
     */
    public function getActiveWebhookSecret(): string
    {
        $suffix = $this->isProductionMode() ? '_prod' : '_test';
        return $this->getDecryptedConfigValue('webhook_secret' . $suffix, '');
    }

    /**
     * Get a decrypted config value for sensitive credentials
     *
     * Cette methode recupere une valeur de configuration et la dechiffre
     * si elle est chiffree. Supporte les valeurs legacy (non chiffrees).
     *
     * @param string $key Le nom de la cle de configuration
     * @param string $default La valeur par defaut si la cle n'existe pas
     * @return string La valeur en clair
     */
    private function getDecryptedConfigValue(string $key, string $default = ''): string
    {
        $value = self::getConfigValue($key, $default);

        if (empty($value)) {
            return $default;
        }

        $encryptionService = $this->getEncryptionService();

        if ($encryptionService->isSensitiveKey($key)) {
            try {
                $value = $encryptionService->decrypt($value);
            } catch (\Exception $e) {
                // Decryption failed — likely a legacy plaintext value that
                // isEncrypted() misidentified. Return the original value as-is
                // rather than losing it by returning the empty default.
                Tlog::getInstance()->warning(
                    '[CawlPayment] Could not decrypt config "' . $key . '", treating as plaintext: ' . $e->getMessage()
                );
            }
        }

        return $value;
    }

    /**
     * Get the encryption service instance (singleton)
     */
    private function getEncryptionService(): CredentialsEncryptionService
    {
        if (self::$encryptionService === null) {
            self::$encryptionService = new CredentialsEncryptionService();
        }

        return self::$encryptionService;
    }

    /**
     * Check if detailed logging is enabled
     */
    public function isLoggingEnabled(): bool
    {
        return (bool) self::getConfigValue('enable_logging', true);
    }

    /**
     * Get webhook URL
     */
    public function getWebhookUrl(): string
    {
        $baseUrl = \Thelia\Model\ConfigQuery::read('url_site', '');
        return rtrim($baseUrl, '/') . '/cawlpayment/webhook';
    }

    /**
     * Get all payment methods grouped by category
     */
    public static function getPaymentMethodsByCategory(): array
    {
        $byCategory = [];

        foreach (self::PAYMENT_METHODS as $code => $method) {
            $category = $method['category'];
            if (!isset($byCategory[$category])) {
                $byCategory[$category] = [
                    'name' => self::CATEGORIES[$category] ?? $category,
                    'methods' => [],
                ];
            }
            $byCategory[$category]['methods'][$code] = $method;
        }

        return $byCategory;
    }
}
