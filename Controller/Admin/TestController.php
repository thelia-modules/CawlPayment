<?php

declare(strict_types=1);

namespace CawlPayment\Controller\Admin;

use CawlPayment\Service\CawlApiService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;

/**
 * Admin controller for CAWL Payment API testing
 */
class TestController
{
    public function __construct(
        private readonly SecurityContext $securityContext
    ) {
    }

    /**
     * Check if user has admin access to this module
     */
    private function checkAdminAccess(string $access = AccessManager::VIEW): bool
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
     * Test API connection using the official SDK
     */
    #[Route(path: '/admin/cawlpayment/api-test/connection', name: 'cawlpayment.admin.api_test_connection', methods: ['POST'])]
    public function testConnectionAction(Request $request): JsonResponse
    {
        if (!$this->checkAdminAccess()) {
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

    /**
     * Get configuration summary
     */
    #[Route(path: '/admin/cawlpayment/api-test/config', name: 'cawlpayment.admin.api_test_config', methods: ['GET'])]
    public function configurationAction(Request $request): JsonResponse
    {
        if (!$this->checkAdminAccess()) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $apiService = new CawlApiService();
            $config = $apiService->getConfigurationSummary();

            return new JsonResponse([
                'success' => true,
                'configuration' => $config,
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available payment products from the API
     */
    #[Route(path: '/admin/cawlpayment/api-test/products', name: 'cawlpayment.admin.api_test_products', methods: ['GET'])]
    public function paymentProductsAction(Request $request): JsonResponse
    {
        if (!$this->checkAdminAccess()) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $amount = (int) $request->query->get('amount', 10000);
            $currency = $request->query->get('currency', 'EUR');
            $country = $request->query->get('country', 'FR');

            $apiService = new CawlApiService();
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
     * Create a test hosted checkout (10 EUR)
     */
    #[Route(path: '/admin/cawlpayment/api-test/create-checkout', name: 'cawlpayment.admin.api_test_create_checkout', methods: ['POST'])]
    public function createTestCheckoutAction(Request $request): JsonResponse
    {
        if (!$this->checkAdminAccess(AccessManager::UPDATE)) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $amount = (int) $request->query->get('amount', 1000);
            $currency = $request->query->get('currency', 'EUR');

            $apiService = new CawlApiService();
            $result = $apiService->createTestHostedCheckout($amount, $currency);

            return new JsonResponse($result);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get hosted checkout status
     */
    #[Route(path: '/admin/cawlpayment/api-test/checkout-status/{hostedCheckoutId}', name: 'cawlpayment.admin.api_test_checkout_status', requirements: ['hostedCheckoutId' => '[a-zA-Z0-9_-]+'], methods: ['GET'])]
    public function checkoutStatusAction(Request $request, string $hostedCheckoutId): JsonResponse
    {
        if (!$this->checkAdminAccess()) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $apiService = new CawlApiService();
            $result = $apiService->getHostedCheckoutStatus($hostedCheckoutId);

            return new JsonResponse($result);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get payment status
     */
    #[Route(path: '/admin/cawlpayment/api-test/payment-status/{paymentId}', name: 'cawlpayment.admin.api_test_payment_status', requirements: ['paymentId' => '[a-zA-Z0-9_-]+'], methods: ['GET'])]
    public function paymentStatusAction(Request $request, string $paymentId): JsonResponse
    {
        if (!$this->checkAdminAccess()) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        try {
            $apiService = new CawlApiService();
            $result = $apiService->getPaymentStatus($paymentId);

            return new JsonResponse($result);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Test return page after checkout (displays result)
     */
    #[Route(path: '/admin/cawlpayment/test-return', name: 'cawlpayment.admin.api_test_return', methods: ['GET'])]
    public function testReturnAction(Request $request): Response
    {
        $hostedCheckoutId = $request->query->get('hostedCheckoutId');
        $returnMac = $request->query->get('RETURNMAC');

        $status = null;
        $error = null;

        if ($hostedCheckoutId) {
            try {
                $apiService = new CawlApiService();
                $status = $apiService->getHostedCheckoutStatus($hostedCheckoutId);
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        }

        $html = '<!DOCTYPE html>
<html>
<head>
    <title>CAWL Payment - Test Return</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .success { color: #28a745; }
        .error { color: #dc3545; }
        .info { color: #17a2b8; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; }
        .back-link { margin-top: 20px; }
        .back-link a { color: #007bff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1>CAWL Payment - Test Return Page</h1>';

        if ($error) {
            $html .= '<p class="error">Error: ' . htmlspecialchars($error) . '</p>';
        } elseif ($status) {
            $statusClass = ($status['isPaid'] ?? false) ? 'success' : 'info';
            $html .= '<p class="' . $statusClass . '">Status: ' . htmlspecialchars($status['status'] ?? 'Unknown') . '</p>';
            $html .= '<h3>Full Response:</h3>';
            $html .= '<pre>' . htmlspecialchars(json_encode($status, JSON_PRETTY_PRINT)) . '</pre>';
        } else {
            $html .= '<p class="info">No hosted checkout ID provided.</p>';
        }

        $html .= '
        <div class="back-link">
            <a href="/admin/module/CawlPayment">&larr; Back to Configuration</a>
        </div>
    </div>
</body>
</html>';

        return new Response($html);
    }

    /**
     * Show test dashboard with all available tests
     */
    #[Route(path: '/admin/cawlpayment/api-test', name: 'cawlpayment.admin.test_dashboard', methods: ['GET'])]
    public function dashboardAction(Request $request): Response
    {
        if (!$this->checkAdminAccess()) {
            return new Response('Access denied', 403);
        }

        $apiService = new CawlApiService();
        $config = $apiService->getConfigurationSummary();

        $html = '<!DOCTYPE html>
<html>
<head>
    <title>CAWL Payment - API Test Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        h1 { color: #333; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card h2 { margin-top: 0; color: #444; font-size: 1.2em; }
        .btn { display: inline-block; padding: 10px 20px; border-radius: 4px; text-decoration: none; font-size: 14px; cursor: pointer; border: none; margin-right: 10px; margin-bottom: 10px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #333; }
        .btn:hover { opacity: 0.9; }
        .config-table { width: 100%; border-collapse: collapse; }
        .config-table td { padding: 8px; border-bottom: 1px solid #eee; }
        .config-table td:first-child { font-weight: bold; width: 200px; }
        .status-ok { color: #28a745; }
        .status-warn { color: #ffc107; }
        .status-error { color: #dc3545; }
        #result { margin-top: 20px; }
        #result pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; max-height: 400px; }
        .loading { color: #666; font-style: italic; }
    </style>
</head>
<body>
    <div class="container">
        <h1>CAWL Payment - API Test Dashboard</h1>

        <div class="card">
            <h2>Current Configuration</h2>
            <table class="config-table">
                <tr>
                    <td>Environment:</td>
                    <td>' . htmlspecialchars($config['environment']) . '</td>
                </tr>
                <tr>
                    <td>Endpoint:</td>
                    <td>' . htmlspecialchars($config['endpoint']) . '</td>
                </tr>
                <tr>
                    <td>Merchant ID (PSPID):</td>
                    <td>' . htmlspecialchars($config['merchant_id'] ?: '<span class="status-error">Not configured</span>') . '</td>
                </tr>
                <tr>
                    <td>API Key:</td>
                    <td class="' . ($config['api_key_configured'] ? 'status-ok' : 'status-error') . '">' . ($config['api_key_configured'] ? 'Configured' : 'Not configured') . '</td>
                </tr>
                <tr>
                    <td>API Secret:</td>
                    <td class="' . ($config['api_secret_configured'] ? 'status-ok' : 'status-error') . '">' . ($config['api_secret_configured'] ? 'Configured' : 'Not configured') . '</td>
                </tr>
                <tr>
                    <td>Logging:</td>
                    <td>' . ($config['logging_enabled'] ? 'Enabled' : 'Disabled') . '</td>
                </tr>
                <tr>
                    <td>Enabled Methods:</td>
                    <td>' . (empty($config['enabled_methods']) ? '<span class="status-warn">None</span>' : implode(', ', $config['enabled_methods'])) . '</td>
                </tr>
            </table>
        </div>

        <div class="card">
            <h2>API Tests</h2>
            <button class="btn btn-primary" onclick="testConnection()">Test Connection</button>
            <button class="btn btn-primary" onclick="getPaymentProducts()">Get Payment Products</button>
            <button class="btn btn-success" onclick="createTestCheckout()">Create Test Checkout (10 EUR)</button>
            <button class="btn btn-warning" onclick="checkStatus()">Check Checkout Status</button>

            <div id="result"></div>
        </div>

        <div class="card">
            <h2>Manual Tests</h2>
            <p>Check a specific checkout or payment status:</p>
            <input type="text" id="checkout_id" placeholder="Hosted Checkout ID" style="padding: 8px; width: 300px; margin-right: 10px;">
            <button class="btn btn-primary" onclick="checkSpecificStatus()">Check Status</button>
        </div>

        <div class="card">
            <a href="/admin/module/CawlPayment">&larr; Back to Configuration</a>
        </div>
    </div>

    <script>
        function showResult(data, error = false) {
            const resultDiv = document.getElementById("result");
            const className = error ? "status-error" : "";
            resultDiv.innerHTML = \'<pre class="\' + className + \'">\' + JSON.stringify(data, null, 2) + \'</pre>\';
        }

        function showLoading() {
            document.getElementById("result").innerHTML = \'<p class="loading">Loading...</p>\';
        }

        async function testConnection() {
            showLoading();
            try {
                const response = await fetch("/admin/cawlpayment/api-test/connection", { method: "POST" });
                const data = await response.json();
                showResult(data, !data.success);
            } catch (e) {
                showResult({ error: e.message }, true);
            }
        }

        async function getPaymentProducts() {
            showLoading();
            try {
                const response = await fetch("/admin/cawlpayment/api-test/products");
                const data = await response.json();
                showResult(data, !data.success);
            } catch (e) {
                showResult({ error: e.message }, true);
            }
        }

        async function createTestCheckout() {
            showLoading();
            try {
                const response = await fetch("/admin/cawlpayment/api-test/create-checkout", { method: "POST" });
                const data = await response.json();
                showResult(data, !data.success);

                if (data.success && data.redirectUrl) {
                    if (confirm("Checkout created! Open payment page in new tab?")) {
                        window.open(data.redirectUrl, "_blank");
                    }
                }
            } catch (e) {
                showResult({ error: e.message }, true);
            }
        }

        async function checkStatus() {
            const checkoutId = prompt("Enter Hosted Checkout ID:");
            if (!checkoutId) return;

            showLoading();
            try {
                const response = await fetch("/admin/cawlpayment/api-test/checkout-status/" + encodeURIComponent(checkoutId));
                const data = await response.json();
                showResult(data, !data.success);
            } catch (e) {
                showResult({ error: e.message }, true);
            }
        }

        async function checkSpecificStatus() {
            const checkoutId = document.getElementById("checkout_id").value;
            if (!checkoutId) {
                alert("Please enter a Hosted Checkout ID");
                return;
            }

            showLoading();
            try {
                const response = await fetch("/admin/cawlpayment/api-test/checkout-status/" + encodeURIComponent(checkoutId));
                const data = await response.json();
                showResult(data, !data.success);
            } catch (e) {
                showResult({ error: e.message }, true);
            }
        }
    </script>
</body>
</html>';

        return new Response($html);
    }
}
