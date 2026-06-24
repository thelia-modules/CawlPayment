<?php

declare(strict_types=1);

namespace CawlPayment\Controller\Admin;

use CawlPayment\CawlPayment;
use CawlPayment\Service\CawlApiService;
use CawlPayment\Service\SecureConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Translation\Translator;
use Thelia\Tools\URL;

/**
 * Admin controller for CAWL Payment configuration
 */
class ConfigurationController extends BaseAdminController
{
    /**
     * Clé de session pour le token CSRF (doit correspondre à AdminHook)
     */
    private const CSRF_TOKEN_KEY = 'cawlpayment_csrf_token';

    public function __construct(
        private readonly SecureConfigService $secureConfigService,
        private readonly CawlApiService $apiService
    ) {
    }

    public function saveAction(Request $request): Response
    {
        if (null !== $response = $this->checkAuth(
            AdminResources::MODULE,
            ['CawlPayment'],
            AccessManager::UPDATE
        )) {
            return $response;
        }

        // Get form data directly from request
        $formData = $request->request->all('cawlpayment_configuration');

        // CSRF Token Validation via session
        $submittedToken = $formData['_token'] ?? null;
        $session = $request->getSession();
        $storedToken = $session->get(self::CSRF_TOKEN_KEY);

        // Supprimer le token après utilisation (one-time use)
        $session->remove(self::CSRF_TOKEN_KEY);

        if (empty($submittedToken) || empty($storedToken) || !hash_equals($storedToken, $submittedToken)) {
            return new RedirectResponse(
                URL::getInstance()->absoluteUrl('/admin/module/CawlPayment', [
                    'error' => Translator::getInstance()->trans(
                        'Invalid security token. Please try again.',
                        [],
                        CawlPayment::DOMAIN_NAME
                    )
                ])
            );
        }

        try {

            // Save credentials
            if (isset($formData['pspid'])) {
                CawlPayment::setConfigValue('pspid', $formData['pspid']);
            }

            // Only update API keys if they're not empty (allow keeping existing values)
            // Use SecureConfigService to encrypt sensitive credentials
            if (!empty($formData['api_key_test'])) {
                $this->secureConfigService->setConfigValue('api_key_test', $formData['api_key_test']);
            }
            if (!empty($formData['api_secret_test'])) {
                $this->secureConfigService->setConfigValue('api_secret_test', $formData['api_secret_test']);
            }
            if (!empty($formData['api_key_prod'])) {
                $this->secureConfigService->setConfigValue('api_key_prod', $formData['api_key_prod']);
            }
            if (!empty($formData['api_secret_prod'])) {
                $this->secureConfigService->setConfigValue('api_secret_prod', $formData['api_secret_prod']);
            }

            // Save webhook keys (encrypted via SecureConfigService)
            if (isset($formData['webhook_key_test'])) {
                $this->secureConfigService->setConfigValue('webhook_key_test', $formData['webhook_key_test']);
            }
            if (!empty($formData['webhook_secret_test'])) {
                $this->secureConfigService->setConfigValue('webhook_secret_test', $formData['webhook_secret_test']);
            }
            if (isset($formData['webhook_key_prod'])) {
                $this->secureConfigService->setConfigValue('webhook_key_prod', $formData['webhook_key_prod']);
            }
            if (!empty($formData['webhook_secret_prod'])) {
                $this->secureConfigService->setConfigValue('webhook_secret_prod', $formData['webhook_secret_prod']);
            }

            // Save environment (strict validation: only 'test' or 'production' allowed)
            $environment = $formData['environment'] ?? CawlPayment::ENV_TEST;
            $allowedEnvironments = [CawlPayment::ENV_TEST, CawlPayment::ENV_PRODUCTION];
            if (!in_array($environment, $allowedEnvironments, true)) {
                return new RedirectResponse(
                    URL::getInstance()->absoluteUrl('/admin/module/CawlPayment', [
                        'error' => Translator::getInstance()->trans(
                            'Invalid environment value. Allowed values: test, production.',
                            [],
                            CawlPayment::DOMAIN_NAME
                        )
                    ])
                );
            }
            CawlPayment::setConfigValue('environment', $environment);

            // Save enabled methods
            $enabledMethods = $formData['enabled_methods'] ?? '';
            CawlPayment::setConfigValue('enabled_methods', $enabledMethods);

            // Save options
            $enableLogging = isset($formData['enable_logging']) ? '1' : '0';
            CawlPayment::setConfigValue('enable_logging', $enableLogging);
            CawlPayment::setConfigValue('checkout_description', $formData['checkout_description'] ?? '');
            CawlPayment::setConfigValue('min_amount', $formData['min_amount'] ?? '0');
            CawlPayment::setConfigValue('max_amount', $formData['max_amount'] ?? '0');

            // Save webhook IP whitelist settings
            CawlPayment::setConfigValue('webhook_ip_whitelist', $formData['webhook_ip_whitelist'] ?? '');
            $webhookWhitelistEnabled = isset($formData['webhook_whitelist_enabled']) ? '1' : '0';
            CawlPayment::setConfigValue('webhook_whitelist_enabled', $webhookWhitelistEnabled);

            // Save test base URL (ngrok or other tunnel for local dev)
            CawlPayment::setConfigValue('test_base_url', rtrim($formData['test_base_url'] ?? '', '/'));

            return new RedirectResponse(
                URL::getInstance()->absoluteUrl('/admin/module/CawlPayment', ['success' => '1'])
            );

        } catch (\Exception $e) {
            return new RedirectResponse(
                URL::getInstance()->absoluteUrl('/admin/module/CawlPayment', ['error' => urlencode($e->getMessage())])
            );
        }
    }

    public function paymentProductsAction(Request $request): JsonResponse
    {
        if (null !== $response = $this->checkAuth(
            AdminResources::MODULE,
            ['CawlPayment'],
            AccessManager::VIEW
        )) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $apiService = $this->apiService;

            // Get parameters from request
            $amount = (int) $request->query->get('amount', 10000); // Default 100 EUR in cents
            $currency = $request->query->get('currency', 'EUR');
            $country = $request->query->get('country', 'FR');

            $result = $apiService->getPaymentProducts($amount, $currency, $country);

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $this->formatApiError($e),
            ], 500);
        }
    }

    public function createTestTransactionAction(Request $request): JsonResponse
    {
        if (null !== $response = $this->checkAuth(
            AdminResources::MODULE,
            ['CawlPayment'],
            AccessManager::UPDATE
        )) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        if (CawlPayment::getConfigValue('environment') !== CawlPayment::ENV_TEST) {
            return new JsonResponse(['success' => false, 'error' => 'Module must be set to test mode (Admin > CawlPayment > Environment)']);
        }

        if (empty(CawlPayment::getConfigValue('pspid'))
            || empty(CawlPayment::getConfigValue('api_key_test'))
            || empty(CawlPayment::getConfigValue('api_secret_test'))
        ) {
            return new JsonResponse(['success' => false, 'error' => 'Test credentials are incomplete (pspid, api_key_test, api_secret_test required)']);
        }

        $amountCents = (int) $request->request->get('amount', 1000);
        $currency = strtoupper(trim((string) $request->request->get('currency', 'EUR')));

        try {
            $result = $this->apiService->createTestHostedCheckout($amountCents, $currency);

            if (!($result['success'] ?? false)) {
                return new JsonResponse(['success' => false, 'error' => $result['error'] ?? 'Unknown error']);
            }

            return new JsonResponse([
                'success' => true,
                'checkoutUrl' => $result['redirectUrl'] ?? $result['checkoutUrl'] ?? '',
                'hostedCheckoutId' => $result['hostedCheckoutId'] ?? '',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $this->formatApiError($e)], 500);
        }
    }

    public function getLogsAction(Request $request): JsonResponse
    {
        if (null !== $response = $this->checkAuth(
            AdminResources::MODULE,
            ['CawlPayment'],
            AccessManager::VIEW
        )) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        $logFile = \defined('THELIA_ROOT') ? THELIA_ROOT . 'var/log/log-thelia.txt' : null;

        if (!$logFile || !file_exists($logFile)) {
            return new JsonResponse(['success' => true, 'lines' => ['Log file not found: var/log/log-thelia.txt']]);
        }

        $allLines = @file($logFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $recentLines = \array_slice($allLines, -500);
        $cawlLines = array_values(array_filter($recentLines, static fn (string $l): bool => str_contains($l, '[CawlPayment]')));

        return new JsonResponse(['success' => true, 'lines' => \array_slice($cawlLines, -50)]);
    }

    public function testConnectionAction(Request $request): JsonResponse
    {
        if (null !== $response = $this->checkAuth(
            AdminResources::MODULE,
            ['CawlPayment'],
            AccessManager::VIEW
        )) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $apiService = $this->apiService;
            $result = $apiService->testConnection();

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $this->formatApiError($e),
            ], 500);
        }
    }

    private function formatApiError(\Throwable $e): string
    {
        if ($e instanceof \Error && str_contains($e->getMessage(), 'OnlinePayments\Sdk')) {
            return 'Le SDK Worldline n\'est pas installé. Exécutez : composer require online-payments/sdk-php:^5.0';
        }

        return $e->getMessage();
    }
}
