<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Service;

use CawlPayment\CawlPayment;
use CawlPayment\Model\CawlTransaction;
use CawlPayment\Service\CawlApiService;
use CawlPayment\Service\CredentialsEncryptionService;
use CawlPayment\Tests\Mock\TlogMock;
use PHPUnit\Framework\TestCase;
use Thelia\Model\Order;

/**
 * Stub pour injecter un CawlTransaction mockable sans toucher à Propel
 */
class CawlApiServiceStub extends CawlApiService
{
    private ?CawlTransaction $transactionToReturn = null;
    private ?Order $orderToReturn = null;
    public bool $saveWasCalled = false;
    public ?CawlTransaction $savedTransaction = null;

    public function setTransactionToReturn(?CawlTransaction $tx): void
    {
        $this->transactionToReturn = $tx;
    }

    public function setOrderToReturn(?Order $order): void
    {
        $this->orderToReturn = $order;
    }

    protected function findTransactionByMerchantReference(string $merchantRef): ?CawlTransaction
    {
        return $this->transactionToReturn;
    }

    protected function findOrderById(int $orderId): ?Order
    {
        return $this->orderToReturn;
    }

    protected function saveTransaction(CawlTransaction $transaction): void
    {
        $this->saveWasCalled = true;
        $this->savedTransaction = $transaction;
    }
}

/**
 * Tests unitaires pour CawlApiService::processWebhook() et mapCawlStatus()
 *
 * Couvre :
 *  - mapCawlStatus()    : tous les cas de mapping via Reflection
 *  - processWebhook()   : bypass signature (test mode), champs manquants,
 *                         transaction introuvable, mise à jour status
 */
class CawlApiServiceProcessWebhookTest extends TestCase
{
    private CawlApiServiceStub $service;
    private \ReflectionMethod $mapCawlStatus;

    protected function setUp(): void
    {
        CawlPayment::resetConfig();
        TlogMock::reset();

        // Mode test + whitelist IP stricte → le bypass de signature (pas de
        // secret en mode test) est autorisé.
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);
        CawlPayment::setConfigValue('webhook_whitelist_enabled', '1');
        CawlPayment::setConfigValue('webhook_ip_whitelist', '127.0.0.1');

        $encryptionMock = $this->createMock(CredentialsEncryptionService::class);
        $encryptionMock->method('isSensitiveKey')->willReturn(false);

        $reflection = new \ReflectionClass(CawlApiService::class);
        $this->service = new CawlApiServiceStub($encryptionMock);

        $this->mapCawlStatus = new \ReflectionMethod(CawlApiService::class, 'mapCawlStatus');
        $this->mapCawlStatus->setAccessible(true);
    }

    protected function tearDown(): void
    {
        CawlPayment::resetConfig();
        TlogMock::reset();
    }

    // =========================================================================
    // mapCawlStatus() — tous les mappings
    // =========================================================================

    /** @dataProvider provideStatusMappings */
    public function testMapCawlStatusReturnsExpectedValue(string $cawlStatus, string $expected): void
    {
        $result = $this->mapCawlStatus->invoke($this->service, $cawlStatus);
        $this->assertSame($expected, $result);
    }

    public static function provideStatusMappings(): array
    {
        return [
            'PAYMENT_CREATED → pending'          => ['PAYMENT_CREATED', 'pending'],
            'IN_PROGRESS → pending'              => ['IN_PROGRESS', 'pending'],
            'PENDING_PAYMENT → pending'          => ['PENDING_PAYMENT', 'pending'],
            'PENDING_COMPLETION → pending'       => ['PENDING_COMPLETION', 'pending'],
            'PENDING_CAPTURE → authorized'       => ['PENDING_CAPTURE', 'authorized'],
            'AUTHORIZATION_REQUESTED → pending'  => ['AUTHORIZATION_REQUESTED', 'pending'],
            'CAPTURE_REQUESTED → pending'        => ['CAPTURE_REQUESTED', 'pending'],
            'CAPTURED → captured'                => ['CAPTURED', 'captured'],
            'PAID → captured'                    => ['PAID', 'captured'],
            'CANCELLED → cancelled'              => ['CANCELLED', 'cancelled'],
            'REJECTED → rejected'                => ['REJECTED', 'rejected'],
            'REFUNDED → refunded'                => ['REFUNDED', 'refunded'],
            'CHARGEBACKED → chargebacked'        => ['CHARGEBACKED', 'chargebacked'],
            'UNKNOWN_STATUS → lowercase'         => ['UNKNOWN_STATUS', 'unknown_status'],
        ];
    }

    // =========================================================================
    // processWebhook() — signature bypass en mode test
    // =========================================================================

    public function testProcessWebhookBypassesSignatureInTestModeWithNoSecret(): void
    {
        $transaction = $this->createMock(CawlTransaction::class);
        $transaction->method('getOrderId')->willReturn(42);
        $this->service->setTransactionToReturn($transaction);
        $this->service->setOrderToReturn($this->orderMock());

        $payload = $this->validPayload('ORDER-001', 'CAPTURED');

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['is_paid']);
        $this->assertSame(42, $result['order_id']);
    }

    public function testProcessWebhookRejectsWhenNoSecretAndWhitelistNotStrict(): void
    {
        // Test mode, no secret, but the IP whitelist does not restrict callers:
        // the signature cannot be trusted → reject (public preprod spoofing).
        CawlPayment::setConfigValue('webhook_whitelist_enabled', '0');
        CawlPayment::setConfigValue('webhook_ip_whitelist', '');

        $payload = $this->validPayload('ORDER-001', 'CAPTURED');

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('signature', strtolower($result['error']));
    }

    public function testProcessWebhookRejectsInvalidSignatureInProductionMode(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_PRODUCTION);
        CawlPayment::setConfigValue('webhook_secret_prod', 'real-secret');

        $payload = $this->validPayload('ORDER-001', 'CAPTURED');
        $rawBody = json_encode($payload);

        $result = $this->service->processWebhook($payload, 'bad-signature', $rawBody);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('signature', strtolower($result['error']));
    }

    // =========================================================================
    // processWebhook() — champs manquants
    // =========================================================================

    public function testProcessWebhookReturnsErrorWhenPaymentIdMissing(): void
    {
        $payload = [
            'payment' => [
                'status' => 'CAPTURED',
                'paymentOutput' => ['references' => ['merchantReference' => 'ORDER-001']],
            ],
        ];

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    public function testProcessWebhookReturnsErrorWhenMerchantReferenceMissing(): void
    {
        $payload = [
            'payment' => [
                'id' => 'PAY-123',
                'status' => 'CAPTURED',
                'paymentOutput' => ['references' => []],
            ],
        ];

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }

    // =========================================================================
    // processWebhook() — transaction introuvable
    // =========================================================================

    public function testProcessWebhookReturnsErrorWhenTransactionNotFound(): void
    {
        $this->service->setTransactionToReturn(null);

        $payload = $this->validPayload('ORDER-NOT-EXIST', 'CAPTURED');

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertFalse($result['success']);
        $this->assertSame('Transaction not found', $result['error']);
    }

    // =========================================================================
    // processWebhook() — mise à jour du statut
    // =========================================================================

    public function testProcessWebhookUpdatesTransactionAndReturnsPaidForCaptured(): void
    {
        $transaction = $this->createMock(CawlTransaction::class);
        $transaction->method('getOrderId')->willReturn(10);
        $transaction->expects($this->once())->method('setTransactionRef')->with('PAY-CAPTURED');
        $transaction->expects($this->once())->method('setStatus')->with('captured');
        $this->service->setTransactionToReturn($transaction);
        $this->service->setOrderToReturn($this->orderMock());

        $payload = $this->validPayload('ORDER-10', 'CAPTURED', 'PAY-CAPTURED');

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['is_paid']);
        $this->assertTrue($this->service->saveWasCalled);
    }

    public function testProcessWebhookReturnsPaidFalseForPendingStatus(): void
    {
        $transaction = $this->createMock(CawlTransaction::class);
        $transaction->method('getOrderId')->willReturn(20);
        $this->service->setTransactionToReturn($transaction);

        $payload = $this->validPayload('ORDER-20', 'PAYMENT_CREATED');

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertTrue($result['success']);
        $this->assertFalse($result['is_paid']);
    }

    public function testProcessWebhookStatusIsMappedCorrectly(): void
    {
        $transaction = $this->createMock(CawlTransaction::class);
        $transaction->method('getOrderId')->willReturn(30);
        $transaction->expects($this->once())->method('setStatus')->with('cancelled');
        $this->service->setTransactionToReturn($transaction);

        $payload = $this->validPayload('ORDER-30', 'CANCELLED');

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertTrue($result['success']);
        $this->assertFalse($result['is_paid']);
        $this->assertSame('cancelled', $result['status']);
    }

    // =========================================================================
    // processWebhook() — vérification montant/devise (défense en profondeur)
    // =========================================================================

    public function testProcessWebhookRejectsWhenPaidAmountDiffersFromOrder(): void
    {
        $transaction = $this->createMock(CawlTransaction::class);
        $transaction->method('getOrderId')->willReturn(50);
        $this->service->setTransactionToReturn($transaction);
        $this->service->setOrderToReturn($this->orderMock(10000, 'EUR'));

        // Gateway reports 1.00 EUR while the order total is 100.00 EUR
        $payload = $this->validPayload('ORDER-50', 'CAPTURED', 'PAY-50', 100, 'EUR');

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('is_paid', $result);
    }

    public function testProcessWebhookRejectsWhenCurrencyDiffersFromOrder(): void
    {
        $transaction = $this->createMock(CawlTransaction::class);
        $transaction->method('getOrderId')->willReturn(51);
        $this->service->setTransactionToReturn($transaction);
        $this->service->setOrderToReturn($this->orderMock(10000, 'EUR'));

        $payload = $this->validPayload('ORDER-51', 'CAPTURED', 'PAY-51', 10000, 'USD');

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertFalse($result['success']);
    }

    public function testProcessWebhookRejectsWhenAmountOfMoneyMissing(): void
    {
        $transaction = $this->createMock(CawlTransaction::class);
        $transaction->method('getOrderId')->willReturn(52);
        $this->service->setTransactionToReturn($transaction);
        $this->service->setOrderToReturn($this->orderMock(10000, 'EUR'));

        $payload = [
            'payment' => [
                'id' => 'PAY-52',
                'status' => 'CAPTURED',
                'paymentOutput' => ['references' => ['merchantReference' => 'ORDER-52']],
            ],
        ];

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertFalse($result['success']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function validPayload(
        string $merchantRef,
        string $status,
        string $paymentId = 'PAY-001',
        int $amount = 10000,
        string $currency = 'EUR'
    ): array {
        return [
            'payment' => [
                'id' => $paymentId,
                'status' => $status,
                'statusOutput' => ['statusCode' => 9],
                'paymentOutput' => [
                    'references' => ['merchantReference' => $merchantRef],
                    'amountOfMoney' => ['amount' => $amount, 'currencyCode' => $currency],
                ],
            ],
        ];
    }

    /**
     * Order mock whose total matches $amountCents / 100 in $currency.
     */
    private function orderMock(int $amountCents = 10000, string $currency = 'EUR'): Order
    {
        $currencyMock = $this->createMock(\Thelia\Model\Currency::class);
        $currencyMock->method('getCode')->willReturn($currency);

        $order = $this->createMock(Order::class);
        $order->method('getTotalAmount')->willReturn($amountCents / 100);
        $order->method('getCurrency')->willReturn($currencyMock);

        return $order;
    }
}
