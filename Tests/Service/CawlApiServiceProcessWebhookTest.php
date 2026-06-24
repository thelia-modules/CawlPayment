<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Service;

use CawlPayment\CawlPayment;
use CawlPayment\Model\CawlTransaction;
use CawlPayment\Service\CawlApiService;
use CawlPayment\Service\CredentialsEncryptionService;
use CawlPayment\Tests\Mock\TlogMock;
use PHPUnit\Framework\TestCase;

/**
 * Stub pour injecter un CawlTransaction mockable sans toucher à Propel
 */
class CawlApiServiceStub extends CawlApiService
{
    private ?CawlTransaction $transactionToReturn = null;
    public bool $saveWasCalled = false;
    public ?CawlTransaction $savedTransaction = null;

    public function setTransactionToReturn(?CawlTransaction $tx): void
    {
        $this->transactionToReturn = $tx;
    }

    protected function findTransactionByMerchantReference(string $merchantRef): ?CawlTransaction
    {
        return $this->transactionToReturn;
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

        // Mode test, pas de webhook secret → signature toujours bypassée
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);

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

        $payload = $this->validPayload('ORDER-001', 'CAPTURED');

        $result = $this->service->processWebhook($payload, '', json_encode($payload));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['is_paid']);
        $this->assertSame(42, $result['order_id']);
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
    // Helpers
    // =========================================================================

    private function validPayload(string $merchantRef, string $status, string $paymentId = 'PAY-001'): array
    {
        return [
            'payment' => [
                'id' => $paymentId,
                'status' => $status,
                'statusOutput' => ['statusCode' => 9],
                'paymentOutput' => [
                    'references' => ['merchantReference' => $merchantRef],
                ],
            ],
        ];
    }
}
