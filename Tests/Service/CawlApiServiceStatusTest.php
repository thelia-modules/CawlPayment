<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Service;

use CawlPayment\Service\CawlApiService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour la méthode isSuccessStatus de CawlApiService
 *
 * Vérifie que seuls les statuts de paiement encaissé (CAPTURED, PAID) sont
 * considérés comme des paiements réussis. PENDING_CAPTURE (autorisé mais non
 * capturé) et PAYMENT_CREATED (paiement seulement initié) ne doivent PAS être
 * des statuts de succès.
 */
class CawlApiServiceStatusTest extends TestCase
{
    private CawlApiService $service;

    protected function setUp(): void
    {
        // Use reflection to bypass the constructor which requires CawlPayment module
        $reflection = new \ReflectionClass(CawlApiService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
    }

    /**
     * @dataProvider successStatusProvider
     */
    public function testIsSuccessStatusReturnsTrue(string $status): void
    {
        $this->assertTrue($this->service->isSuccessStatus($status));
    }

    public static function successStatusProvider(): array
    {
        return [
            'CAPTURED' => ['CAPTURED'],
            'PAID' => ['PAID'],
        ];
    }

    /**
     * @dataProvider nonSuccessStatusProvider
     */
    public function testIsSuccessStatusReturnsFalse(string $status): void
    {
        $this->assertFalse($this->service->isSuccessStatus($status));
    }

    public static function nonSuccessStatusProvider(): array
    {
        return [
            'PAYMENT_CREATED' => ['PAYMENT_CREATED'],
            'IN_PROGRESS' => ['IN_PROGRESS'],
            'PENDING_PAYMENT' => ['PENDING_PAYMENT'],
            'PENDING_COMPLETION' => ['PENDING_COMPLETION'],
            'PENDING_CAPTURE' => ['PENDING_CAPTURE'],
            'AUTHORIZATION_REQUESTED' => ['AUTHORIZATION_REQUESTED'],
            'CANCELLED' => ['CANCELLED'],
            'REFUNDED' => ['REFUNDED'],
            'REJECTED' => ['REJECTED'],
            'UNKNOWN' => ['UNKNOWN'],
        ];
    }

    /**
     * Régression: PAYMENT_CREATED ne doit jamais être un statut de succès (THE-138)
     */
    public function testPaymentCreatedIsNotSuccessStatus(): void
    {
        $this->assertFalse(
            $this->service->isSuccessStatus('PAYMENT_CREATED'),
            'PAYMENT_CREATED must NOT be a success status — it only means payment was initiated, not completed'
        );
    }
}
