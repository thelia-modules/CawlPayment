<?php

declare(strict_types=1);

namespace CawlPayment\Controller\Front;

use CawlPayment\Service\CawlApiService;
use CawlPayment\Service\IpWhitelistService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Log\Tlog;
use Thelia\Model\OrderQuery;
use Thelia\Model\OrderStatusQuery;

/**
 * Webhook controller for CAWL Payment notifications
 */
class WebhookController extends BaseFrontController
{
    private EventDispatcherInterface $dispatcher;
    private IpWhitelistService $ipWhitelistService;
    private CawlApiService $apiService;

    public function __construct(
        EventDispatcherInterface $dispatcher,
        IpWhitelistService $ipWhitelistService,
        CawlApiService $apiService
    ) {
        $this->dispatcher = $dispatcher;
        $this->ipWhitelistService = $ipWhitelistService;
        $this->apiService = $apiService;
    }
    public function handleAction(Request $request): Response
    {
        $logger = $this->getLogger();

        // IP Whitelist verification
        $clientIp = $request->getClientIp() ?? '';
        if (!$this->ipWhitelistService->isIpAllowed($clientIp)) {
            $logger->addError(
                '[CawlPayment Webhook] Rejected request from unauthorized IP: ' . $clientIp
            );
            return new Response('Forbidden', 403);
        }

        $logger->addInfo('[CawlPayment Webhook] Received webhook notification from IP: ' . $clientIp);

        try {
            // Get raw body
            $rawBody = $request->getContent();

            if (empty($rawBody)) {
                $logger->addError('[CawlPayment Webhook] Empty request body');
                return new Response('Empty body', 400);
            }

            // Parse JSON
            $payload = json_decode($rawBody, true);

            if (!$payload) {
                $logger->addError('[CawlPayment Webhook] Invalid JSON: ' . json_last_error_msg());
                return new Response('Invalid JSON', 400);
            }

            $logger->addInfo('[CawlPayment Webhook] Payload: ' . substr($rawBody, 0, 1000));

            // Get signature header
            $signature = $request->headers->get('X-GCS-Signature', '');

            // Process webhook
            $result = $this->apiService->processWebhook($payload, $signature, $rawBody);

            if (!$result['success']) {
                $logger->addError('[CawlPayment Webhook] Processing failed: ' . ($result['error'] ?? 'Unknown'));
                return new Response($result['error'] ?? 'Error', 400);
            }

            // If payment is successful, update order status
            if ($result['is_paid'] && isset($result['order_id'])) {
                $this->confirmPayment($result['order_id'], $logger);
            }

            $logger->addInfo('[CawlPayment Webhook] Successfully processed for order #' . ($result['order_id'] ?? 'unknown'));

            return new Response('OK', 200);

        } catch (\Exception $e) {
            $logger->addError('[CawlPayment Webhook] Exception: ' . $e->getMessage());
            // Don't expose internal error details to external callers
            return new Response('Internal error', 500);
        }
    }

    /**
     * Confirm payment and update order status to PAID
     */
    protected function confirmPayment(int $orderId, Tlog $logger): void
    {
        $order = OrderQuery::create()->findPk($orderId);

        if (!$order) {
            $logger->addError("[CawlPayment Webhook] Order #{$orderId} not found");
            return;
        }

        // Check if already paid
        $paidStatus = OrderStatusQuery::getPaidStatus();
        if ($order->getStatusId() === $paidStatus->getId()) {
            $logger->addInfo("[CawlPayment Webhook] Order #{$orderId} already paid");
            return;
        }

        // Update order status using the injected dispatcher
        $event = new OrderEvent($order);
        $event->setStatus($paidStatus->getId());
        $this->dispatcher->dispatch($event, TheliaEvents::ORDER_UPDATE_STATUS);

        $logger->addInfo("[CawlPayment Webhook] Order #{$orderId} marked as PAID");
    }

    /**
     * Get logger instance
     */
    private function getLogger(): Tlog
    {
        $logger = Tlog::getNewInstance();
        $logger->setDestinations('\\Thelia\\Log\\Destination\\TlogDestinationFile');
        $logger->setConfig('\\Thelia\\Log\\Destination\\TlogDestinationFile', 0, THELIA_LOG_DIR . 'cawlpayment.log');
        return $logger;
    }
}
