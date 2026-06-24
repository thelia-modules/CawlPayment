<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Controller\Front;

use CawlPayment\CawlPayment;
use CawlPayment\Controller\Front\WebhookController;
use CawlPayment\Service\CawlApiService;
use CawlPayment\Service\IpWhitelistService;
use CawlPayment\Tests\Mock\TlogMock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Log\Tlog;

/**
 * Stub permettant d'intercepter confirmPayment() sans passer par Propel
 */
class WebhookControllerTestable extends WebhookController
{
    public bool $confirmPaymentCalled = false;
    public ?int $confirmedOrderId = null;

    protected function confirmPayment(int $orderId, Tlog $logger): void
    {
        $this->confirmPaymentCalled = true;
        $this->confirmedOrderId = $orderId;
    }
}

/**
 * Tests unitaires pour WebhookController::handleAction()
 *
 * Couvre :
 *  - IP non whitelistée → 403
 *  - Body vide → 400
 *  - JSON invalide → 400
 *  - processWebhook() échoue → 400
 *  - processWebhook() succès is_paid=false → 200, confirmPayment non appelé
 *  - processWebhook() succès is_paid=true → 200, confirmPayment appelé
 *  - Exception interne → 500
 */
class WebhookControllerTest extends TestCase
{
    private IpWhitelistService $ipWhitelistMock;
    private CawlApiService $apiServiceMock;
    private EventDispatcherInterface $dispatcherMock;

    protected function setUp(): void
    {
        CawlPayment::resetConfig();
        TlogMock::reset();

        $this->ipWhitelistMock = $this->createMock(IpWhitelistService::class);
        $this->apiServiceMock = $this->createMock(CawlApiService::class);
        $this->dispatcherMock = $this->createMock(EventDispatcherInterface::class);
    }

    protected function tearDown(): void
    {
        CawlPayment::resetConfig();
        TlogMock::reset();
    }

    private function makeController(): WebhookControllerTestable
    {
        return new WebhookControllerTestable(
            $this->dispatcherMock,
            $this->ipWhitelistMock,
            $this->apiServiceMock
        );
    }

    private function makeRequest(string $body, string $ip = '127.0.0.1'): Request
    {
        $request = Request::create('/cawlpayment/webhook', 'POST', [], [], [], [], $body);
        $request->server->set('REMOTE_ADDR', $ip);
        return $request;
    }

    // =========================================================================
    // Guard : IP non autorisée
    // =========================================================================

    public function testHandleActionReturns403WhenIpNotAllowed(): void
    {
        $this->ipWhitelistMock->method('isIpAllowed')->willReturn(false);

        $response = $this->makeController()->handleAction($this->makeRequest('{}'));

        $this->assertSame(403, $response->getStatusCode());
    }

    // =========================================================================
    // Guard : body vide / JSON invalide
    // =========================================================================

    public function testHandleActionReturns400WhenBodyIsEmpty(): void
    {
        $this->ipWhitelistMock->method('isIpAllowed')->willReturn(true);

        $response = $this->makeController()->handleAction($this->makeRequest(''));

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testHandleActionReturns400WhenBodyIsInvalidJson(): void
    {
        $this->ipWhitelistMock->method('isIpAllowed')->willReturn(true);

        $response = $this->makeController()->handleAction($this->makeRequest('not-json'));

        $this->assertSame(400, $response->getStatusCode());
    }

    // =========================================================================
    // processWebhook() échoue
    // =========================================================================

    public function testHandleActionReturns400WhenProcessWebhookFails(): void
    {
        $this->ipWhitelistMock->method('isIpAllowed')->willReturn(true);
        $this->apiServiceMock->method('processWebhook')->willReturn([
            'success' => false,
            'error' => 'Invalid signature',
        ]);

        $response = $this->makeController()->handleAction($this->makeRequest('{"payment":{}}'));

        $this->assertSame(400, $response->getStatusCode());
    }

    // =========================================================================
    // Succès — is_paid = false
    // =========================================================================

    public function testHandleActionReturns200AndSkipsConfirmWhenNotPaid(): void
    {
        $this->ipWhitelistMock->method('isIpAllowed')->willReturn(true);
        $this->apiServiceMock->method('processWebhook')->willReturn([
            'success' => true,
            'is_paid' => false,
            'order_id' => 5,
        ]);

        $controller = $this->makeController();
        $response = $controller->handleAction($this->makeRequest('{"payment":{}}'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($controller->confirmPaymentCalled);
    }

    // =========================================================================
    // Succès — is_paid = true → confirmPayment() doit être appelé
    // =========================================================================

    public function testHandleActionCallsConfirmPaymentWhenPaid(): void
    {
        $this->ipWhitelistMock->method('isIpAllowed')->willReturn(true);
        $this->apiServiceMock->method('processWebhook')->willReturn([
            'success' => true,
            'is_paid' => true,
            'order_id' => 99,
        ]);

        $controller = $this->makeController();
        $response = $controller->handleAction($this->makeRequest('{"payment":{}}'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($controller->confirmPaymentCalled);
        $this->assertSame(99, $controller->confirmedOrderId);
    }

    // =========================================================================
    // Exception interne → 500
    // =========================================================================

    public function testHandleActionReturns500OnUnexpectedException(): void
    {
        $this->ipWhitelistMock->method('isIpAllowed')->willReturn(true);
        $this->apiServiceMock->method('processWebhook')->willThrowException(
            new \RuntimeException('Unexpected crash')
        );

        $response = $this->makeController()->handleAction($this->makeRequest('{"payment":{}}'));

        $this->assertSame(500, $response->getStatusCode());
    }
}
