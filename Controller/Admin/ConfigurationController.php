<?php

declare(strict_types=1);

namespace CawlPayment\Controller\Admin;

use CawlPayment\CawlPayment;
use CawlPayment\Service\CawlApiService;
use CawlPayment\Service\SecureConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Translation\Translator;
use Thelia\Tools\URL;

/**
 * Admin controller for CAWL Payment configuration
 */
class ConfigurationController
{
    /**
     * Clé de session pour le token CSRF (doit correspondre à AdminHook)
     */
    private const CSRF_TOKEN_KEY = 'cawlpayment_csrf_token';

    public function __construct(
        private readonly SecurityContext $securityContext,
        private readonly SecureConfigService $secureConfigService
    ) {
    }

    /**
     * Check if user has admin access to this module
     */
    private function checkAdminAccess(string $access = AccessManager::UPDATE): bool
    {
        if (!$this->securityContext->hasAdminUser()) {
            return false;
        }

        return $this->securityContext->isGranted(
            ['ADMIN'],
            [AdminResources::MODULE],
            ['CawlPayment'],
            [$access]
        );
    }

    /**
     * Save configuration
     */
    #[Route(path: '/admin/cawlpayment/configure', name: 'cawlpayment.admin.configure', methods: ['POST'])]
    public function saveAction(Request $request): RedirectResponse
    {
        if (!$this->checkAdminAccess(AccessManager::UPDATE)) {
            return new RedirectResponse(
                URL::getInstance()->absoluteUrl('/admin/module/CawlPayment', ['error' => 'Access denied'])
            );
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

            // Save environment
            CawlPayment::setConfigValue('environment', $formData['environment'] ?? CawlPayment::ENV_TEST);

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
        if (!$this->checkAdminAccess(AccessManager::VIEW)) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $apiService = new CawlApiService();

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
        if (!$this->checkAdminAccess(AccessManager::VIEW)) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $apiService = new CawlApiService();
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
