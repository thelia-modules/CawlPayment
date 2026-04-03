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
use Symfony\Component\Routing\Attribute\Route;
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

    /**
     * Save configuration
     */
    #[Route(path: '/admin/cawlpayment/configure', name: 'cawlpayment.admin.configure', methods: ['POST'])]
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

            return new RedirectResponse(
                URL::getInstance()->absoluteUrl('/admin/module/CawlPayment', ['success' => '1'])
            );

        } catch (\Exception $e) {
            return new RedirectResponse(
                URL::getInstance()->absoluteUrl('/admin/module/CawlPayment', ['error' => urlencode($e->getMessage())])
            );
        }
    }

    /**
     * Get available payment products from API (with caching)
     */
    #[Route(path: '/admin/module/CawlPayment/payment-products', name: 'cawlpayment.admin.payment_products', methods: ['GET'])]
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
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test API connection
     */
    #[Route(path: '/admin/module/CawlPayment/test-connection', name: 'cawlpayment.admin.test_connection', methods: ['POST'])]
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
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
