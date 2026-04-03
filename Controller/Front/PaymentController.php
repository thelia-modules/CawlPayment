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
use Symfony\Component\Routing\Attribute\Route;
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

    public function __construct(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }
    /**
     * Initiate payment process
     */
    #[Route(path: '/cawlpayment/pay/{orderId}/{methodCode}', name: 'cawlpayment.front.pay', requirements: ['orderId' => '\d+', 'methodCode' => '[a-z0-9_]+'], methods: ['GET', 'POST'])]
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
            $apiService = new CawlApiService();

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

    /**
     * Handle success return from CAWL
     */
    #[Route(path: '/cawlpayment/success', name: 'cawlpayment.front.success', methods: ['GET'])]
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
            $apiService = new CawlApiService();

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

    /**
     * Handle failure return from CAWL
     */
    #[Route(path: '/cawlpayment/failure', name: 'cawlpayment.front.failure', methods: ['GET'])]
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

    /**
     * Handle cancel return from CAWL
     */
    #[Route(path: '/cawlpayment/cancel', name: 'cawlpayment.front.cancel', methods: ['GET'])]
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

    /**
     * Get payment status (AJAX)
     */
    #[Route(path: '/cawlpayment/status/{hostedCheckoutId}', name: 'cawlpayment.front.status', requirements: ['hostedCheckoutId' => '[a-zA-Z0-9_-]+'], methods: ['GET'])]
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
            $apiService = new CawlApiService();

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
}
