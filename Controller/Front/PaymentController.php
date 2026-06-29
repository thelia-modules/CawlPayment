<?php

declare(strict_types=1);

namespace CawlPayment\Controller\Front;

use CawlPayment\CawlPayment;
use CawlPayment\Model\CawlTransactionQuery;
use CawlPayment\Service\CawlApiService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;
use Thelia\Tools\URL;

/**
 * Frontend payment controller for CAWL Payment
 */
class PaymentController extends BaseFrontController
{
    private EventDispatcherInterface $dispatcher;
    private CawlApiService $apiService;

    public function __construct(
        EventDispatcherInterface $dispatcher,
        CawlApiService $apiService
    ) {
        $this->dispatcher = $dispatcher;
        $this->apiService = $apiService;
    }
    public function payAction(Request $request, int $orderId, string $methodCode): Response
    {
        // Get order
        $order = OrderQuery::create()->findPk($orderId);

        if (!$order) {
            return $this->pageNotFound();
        }

        // Verify order belongs to current customer
        $customer = $this->getSecurityContext()->getCustomerUser();
        if (!$customer || $order->getCustomerId() !== $customer->getId()) {
            return $this->pageNotFound();
        }

        // Verify this is our payment module
        $module = new CawlPayment();
        if ($order->getPaymentModuleId() !== $module->getModuleModel()->getId()) {
            return $this->pageNotFound();
        }

        // Verify payment method is enabled
        if (!$module->isPaymentMethodEnabled($methodCode)) {
            return new RedirectResponse(
                URL::getInstance()->absoluteUrl('/order/failed/' . $orderId, ['error' => 'Invalid payment method'])
            );
        }

        try {
            // Create API service
            $apiService = $this->apiService;

            // Build return URLs
            $baseUrl = URL::getInstance()->absoluteUrl('');
            $returnUrl = $baseUrl . '/cawlpayment/success?order_id=' . $orderId;
            $webhookUrl = $baseUrl . '/cawlpayment/webhook';

            // Create hosted checkout
            $response = $apiService->createHostedCheckout($order, $methodCode, $returnUrl, $webhookUrl);

            if (!isset($response['hostedCheckoutId']) || !isset($response['RETURNMAC'])) {
                throw new \Exception('Invalid response from CAWL API');
            }

            // Build checkout URL and redirect
            $checkoutUrl = $apiService->getCheckoutUrl($response['hostedCheckoutId'], $response['RETURNMAC']);

            return new RedirectResponse($checkoutUrl);

        } catch (\Exception $e) {
            // Log error using Thelia logger
            \Thelia\Log\Tlog::getInstance()->error('[CawlPayment] Payment initiation error: ' . $e->getMessage());

            // Redirect to failure page with generic error message (don't expose internal details)
            return new RedirectResponse(
                URL::getInstance()->absoluteUrl('/order/failed/' . $orderId, ['error' => urlencode('Payment initialization failed')])
            );
        }
    }

    public function successAction(Request $request): Response
    {
        $orderId = $request->query->get('order_id');
        $hostedCheckoutId = $request->query->get('hostedCheckoutId');

        if (!$orderId) {
            return $this->pageNotFound();
        }

        $order = OrderQuery::create()->findPk($orderId);
        if (!$order) {
            return $this->pageNotFound();
        }

        // Verify order belongs to current customer
        $customer = $this->getSecurityContext()->getCustomerUser();
        if (!$customer || $order->getCustomerId() !== $customer->getId()) {
            \Thelia\Log\Tlog::getInstance()->warning(
                '[CawlPayment] Unauthorized access attempt to success page for order #' . $orderId .
                ' by customer ' . ($customer ? $customer->getId() : 'anonymous')
            );
            return $this->pageNotFound();
        }

        // Verify this is our payment module
        $module = new CawlPayment();
        if ($order->getPaymentModuleId() !== $module->getModuleModel()->getId()) {
            return $this->pageNotFound();
        }

        try {
            // Get payment status from CAWL
            $apiService = $this->apiService;

            // Find hosted checkout ID from transaction
            $transaction = CawlTransactionQuery::create()
                ->filterByOrderId($orderId)
                ->orderByCreatedAt('desc')
                ->findOne();

            if ($transaction && $transaction->getHostedCheckoutId()) {
                $statusResponse = $apiService->getHostedCheckoutStatus($transaction->getHostedCheckoutId());

                // Check if payment is successful
                if (isset($statusResponse['isPaid']) && $statusResponse['isPaid']) {
                    // Update order status to PAID
                    $this->confirmPayment($order, $statusResponse['paymentId'] ?? $transaction->getTransactionRef());
                }
            }

            // Redirect to order confirmation
            return new RedirectResponse(
                URL::getInstance()->absoluteUrl('/order/placed/' . $orderId)
            );

        } catch (\Exception $e) {
            \Thelia\Log\Tlog::getInstance()->error('[CawlPayment] Success callback error: ' . $e->getMessage());

            // Still redirect to order placed, webhook will handle final status
            return new RedirectResponse(
                URL::getInstance()->absoluteUrl('/order/placed/' . $orderId)
            );
        }
    }

    public function failureAction(Request $request): Response
    {
        $orderId = $request->query->get('order_id');
        $message = $request->query->get('message', 'Payment failed');

        if (!$orderId) {
            return $this->pageNotFound();
        }

        $order = OrderQuery::create()->findPk($orderId);
        if (!$order) {
            return $this->pageNotFound();
        }

        // Verify order belongs to current customer
        $customer = $this->getSecurityContext()->getCustomerUser();
        if (!$customer || $order->getCustomerId() !== $customer->getId()) {
            \Thelia\Log\Tlog::getInstance()->warning(
                '[CawlPayment] Unauthorized access attempt to failure page for order #' . $orderId .
                ' by customer ' . ($customer ? $customer->getId() : 'anonymous')
            );
            return $this->pageNotFound();
        }

        // Verify this is our payment module
        $module = new CawlPayment();
        if ($order->getPaymentModuleId() !== $module->getModuleModel()->getId()) {
            return $this->pageNotFound();
        }

        return new RedirectResponse(
            URL::getInstance()->absoluteUrl('/order/failed/' . $orderId, ['error' => urlencode($message)])
        );
    }

    public function cancelAction(Request $request): Response
    {
        $orderId = $request->query->get('order_id');

        if (!$orderId) {
            return $this->pageNotFound();
        }

        $order = OrderQuery::create()->findPk($orderId);
        if (!$order) {
            return $this->pageNotFound();
        }

        // Verify order belongs to current customer
        $customer = $this->getSecurityContext()->getCustomerUser();
        if (!$customer || $order->getCustomerId() !== $customer->getId()) {
            \Thelia\Log\Tlog::getInstance()->warning(
                '[CawlPayment] Unauthorized access attempt to cancel page for order #' . $orderId .
                ' by customer ' . ($customer ? $customer->getId() : 'anonymous')
            );
            return $this->pageNotFound();
        }

        // Verify this is our payment module
        $module = new CawlPayment();
        if ($order->getPaymentModuleId() !== $module->getModuleModel()->getId()) {
            return $this->pageNotFound();
        }

        return new RedirectResponse(
            URL::getInstance()->absoluteUrl('/order/failed/' . $orderId, ['error' => urlencode('Payment cancelled')])
        );
    }

    public function statusAction(Request $request, string $hostedCheckoutId): JsonResponse
    {
        // Verify customer is authenticated
        $customer = $this->getSecurityContext()->getCustomerUser();
        if (!$customer) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        // Verify the hosted checkout belongs to the current customer
        $transaction = CawlTransactionQuery::create()
            ->filterByHostedCheckoutId($hostedCheckoutId)
            ->findOne();

        if (!$transaction) {
            return new JsonResponse(['success' => false, 'error' => 'Not found'], 404);
        }

        $order = OrderQuery::create()->findPk($transaction->getOrderId());
        if (!$order || $order->getCustomerId() !== $customer->getId()) {
            \Thelia\Log\Tlog::getInstance()->warning(
                '[CawlPayment] Unauthorized status check for checkout ' . $hostedCheckoutId .
                ' by customer ' . $customer->getId()
            );
            return new JsonResponse(['success' => false, 'error' => 'Not found'], 404);
        }

        try {
            $apiService = $this->apiService;

            $status = $apiService->getHostedCheckoutStatus($hostedCheckoutId);

            return new JsonResponse([
                'success' => true,
                'status' => $status['status'] ?? 'unknown',
                'is_paid' => isset($status['status']) && $apiService->isSuccessStatus($status['status']),
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Payment status check failed',
            ], 500);
        }
    }

    /**
     * Confirm payment and update order status
     */
    private function confirmPayment($order, ?string $transactionRef = null): void
    {
        // Get paid status
        $paidStatus = OrderStatusQuery::getPaidStatus();

        if (!$paidStatus) {
            throw new \Exception('Paid order status not found');
        }

        // Update order status using the injected dispatcher
        $event = new OrderEvent($order);
        $event->setStatus($paidStatus->getId());
        $this->dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);

        // Save transaction reference if provided
        if ($transactionRef) {
            $order->setTransactionRef($transactionRef);
            $order->save();
        }
    }

    /**
     * Return URL called by Worldline after a test hosted checkout (cawlpayment:test-transaction command).
     *
     * Returning 200 is enough to close the hosted checkout session properly.
     * The JSON body shows the payment status for debugging purposes.
     */
    public function testReturnAction(Request $request): JsonResponse
    {
        $hostedCheckoutId = $request->query->get('hostedCheckoutId', '');

        if (empty($hostedCheckoutId)) {
            \Thelia\Log\Tlog::getInstance()->warning('[CawlPayment][test-return] Callback reçu sans hostedCheckoutId');

            return new JsonResponse(['success' => false, 'error' => 'Missing hostedCheckoutId'], 400);
        }

        try {
            $status = $this->apiService->getHostedCheckoutStatus($hostedCheckoutId);

            $isPaid = $status['isPaid'] ?? false;
            $logLine = sprintf(
                '[CawlPayment][test-return] hostedCheckoutId=%s | status=%s | paymentStatus=%s | statusCode=%s | isPaid=%s | paymentId=%s',
                $hostedCheckoutId,
                $status['status'] ?? 'n/a',
                $status['paymentStatus'] ?? 'n/a',
                $status['statusCode'] ?? 'n/a',
                $isPaid ? 'oui' : 'non',
                $status['paymentId'] ?? 'n/a'
            );

            // error() used intentionally: Tlog default level is ERROR, info/warning are silently dropped.
            \Thelia\Log\Tlog::getInstance()->error($logLine);

            return new JsonResponse([
                'success' => true,
                'hostedCheckoutId' => $hostedCheckoutId,
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            \Thelia\Log\Tlog::getInstance()->error(
                sprintf('[CawlPayment][test-return] Erreur pour hostedCheckoutId=%s : %s', $hostedCheckoutId, $e->getMessage())
            );

            return new JsonResponse([
                'success' => false,
                'hostedCheckoutId' => $hostedCheckoutId,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
