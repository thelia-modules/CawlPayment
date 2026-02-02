<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Service;

use CawlPayment\CawlPayment;
use CawlPayment\Service\CredentialsEncryptionService;
use CawlPayment\Service\SecureConfigService;
use CawlPayment\Tests\Mock\ConfigQueryMock;
use CawlPayment\Tests\Mock\TlogMock;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour SecureConfigService
 *
 * Couvre l'acces securise aux valeurs de configuration,
 * le chiffrement/dechiffrement transparent, et la migration des credentials.
 */
class SecureConfigServiceTest extends TestCase
{
    private SecureConfigService $service;
    private CredentialsEncryptionService $encryptionService;
    private string $originalEnvKey;

    protected function setUp(): void
    {
        // Sauvegarder la valeur originale
        $this->originalEnvKey = getenv('CAWL_ENCRYPTION_KEY') ?: '';

        // Reinitialiser les mocks
        ConfigQueryMock::reset();
        TlogMock::reset();
        CawlPayment::resetConfig();

        // Definir une cle de chiffrement de test
        $testKey = base64_encode(random_bytes(32));
        putenv('CAWL_ENCRYPTION_KEY=' . $testKey);

        $this->encryptionService = new CredentialsEncryptionService();
        $this->service = new SecureConfigService($this->encryptionService);
    }

    protected function tearDown(): void
    {
        // Restaurer l'environnement original
        if (!empty($this->originalEnvKey)) {
            putenv('CAWL_ENCRYPTION_KEY=' . $this->originalEnvKey);
        } else {
            putenv('CAWL_ENCRYPTION_KEY');
        }

        ConfigQueryMock::reset();
        TlogMock::reset();
        CawlPayment::resetConfig();
    }

    // =========================================================================
    // Tests d'injection de dependances
    // =========================================================================

    public function testEncryptionServiceIsInjected(): void
    {
        $this->assertInstanceOf(
            SecureConfigService::class,
            $this->service,
            'Le service doit etre instancie correctement avec ses dependances'
        );
    }

    // =========================================================================
    // Tests de isSensitiveKey
    // =========================================================================

    public function testIsSensitiveKeyDelegatesToEncryptionService(): void
    {
        // Les cles sensibles
        $this->assertTrue($this->service->isSensitiveKey('api_key_test'));
        $this->assertTrue($this->service->isSensitiveKey('api_key_prod'));
        $this->assertTrue($this->service->isSensitiveKey('api_secret_test'));
        $this->assertTrue($this->service->isSensitiveKey('api_secret_prod'));

        // Les cles non sensibles
        $this->assertFalse($this->service->isSensitiveKey('pspid'));
        $this->assertFalse($this->service->isSensitiveKey('environment'));
    }

    // =========================================================================
    // Tests de migrateCredentials
    // =========================================================================

    public function testMigrateCredentialsReturnsArray(): void
    {
        $result = $this->service->migrateCredentials();

        $this->assertIsArray($result, 'migrateCredentials() doit retourner un array');
        $this->assertArrayHasKey('migrated', $result, 'Le resultat doit contenir une cle "migrated"');
        $this->assertArrayHasKey('already_encrypted', $result, 'Le resultat doit contenir une cle "already_encrypted"');
        $this->assertArrayHasKey('empty', $result, 'Le resultat doit contenir une cle "empty"');
        $this->assertArrayHasKey('errors', $result, 'Le resultat doit contenir une cle "errors"');
    }

    public function testMigrateCredentialsStructure(): void
    {
        $result = $this->service->migrateCredentials();

        $this->assertIsArray($result['migrated']);
        $this->assertIsArray($result['already_encrypted']);
        $this->assertIsArray($result['empty']);
        $this->assertIsArray($result['errors']);
    }

    // =========================================================================
    // Tests de getCredentialsStatus
    // =========================================================================

    public function testGetCredentialsStatusReturnsArray(): void
    {
        $result = $this->service->getCredentialsStatus();

        $this->assertIsArray($result, 'getCredentialsStatus() doit retourner un array');
    }

    public function testGetCredentialsStatusReturnsStatusForAllSensitiveKeys(): void
    {
        $result = $this->service->getCredentialsStatus();
        $sensitiveKeys = $this->encryptionService->getSensitiveKeys();

        foreach ($sensitiveKeys as $key) {
            $this->assertArrayHasKey(
                $key,
                $result,
                "Le statut de la cle '$key' doit etre present"
            );
        }
    }

    public function testGetCredentialsStatusReturnsValidStatuses(): void
    {
        $result = $this->service->getCredentialsStatus();
        $validStatuses = ['empty', 'encrypted', 'plaintext'];

        foreach ($result as $key => $status) {
            $this->assertContains(
                $status,
                $validStatuses,
                "Le statut de '$key' doit etre l'un de: " . implode(', ', $validStatuses)
            );
        }
    }

    // =========================================================================
    // Tests avec mock d'EncryptionService
    // =========================================================================

    public function testServiceWorksWithMockedEncryptionService(): void
    {
        $mockEncryption = $this->createMock(CredentialsEncryptionService::class);

        $mockEncryption->method('isSensitiveKey')
            ->willReturnMap([
                ['api_key_test', true],
                ['pspid', false],
            ]);

        $mockEncryption->method('getSensitiveKeys')
            ->willReturn(['api_key_test', 'api_secret_test']);

        $mockEncryption->method('isEncrypted')
            ->willReturn(false);

        $service = new SecureConfigService($mockEncryption);

        $this->assertTrue($service->isSensitiveKey('api_key_test'));
        $this->assertFalse($service->isSensitiveKey('pspid'));
    }

    // =========================================================================
    // Tests d'integration legere
    // =========================================================================

    public function testIntegrationWithRealEncryptionService(): void
    {
        // Utiliser le vrai service d'encryption
        $result = $this->service->migrateCredentials();

        // Verifier que la migration s'execute sans erreur
        $this->assertIsArray($result);

        // Verifier le statut
        $status = $this->service->getCredentialsStatus();
        $this->assertIsArray($status);

        // Toutes les cles doivent avoir un statut
        $this->assertNotEmpty($status);
    }

    public function testIsSensitiveKeyConsistencyWithEncryptionService(): void
    {
        $sensitiveKeys = $this->encryptionService->getSensitiveKeys();

        foreach ($sensitiveKeys as $key) {
            $this->assertTrue(
                $this->service->isSensitiveKey($key),
                "La cle '$key' doit etre sensible dans les deux services"
            );

            $this->assertTrue(
                $this->encryptionService->isSensitiveKey($key),
                "La cle '$key' doit etre sensible dans EncryptionService"
            );
        }
    }

    // =========================================================================
    // Tests de cas limites
    // =========================================================================

    public function testMigrateCredentialsWithEmptyConfig(): void
    {
        // ConfigQueryMock est vide par defaut
        ConfigQueryMock::reset();

        $result = $this->service->migrateCredentials();

        // Toutes les cles sensibles doivent etre dans 'empty'
        $sensitiveKeys = $this->encryptionService->getSensitiveKeys();
        foreach ($sensitiveKeys as $key) {
            $this->assertContains(
                $key,
                $result['empty'],
                "La cle '$key' doit etre dans 'empty' car non configuree"
            );
        }
    }

    public function testGetCredentialsStatusWithEmptyConfig(): void
    {
        ConfigQueryMock::reset();

        $result = $this->service->getCredentialsStatus();

        // Tous les statuts doivent etre 'empty'
        foreach ($result as $key => $status) {
            $this->assertSame(
                'empty',
                $status,
                "Le statut de '$key' doit etre 'empty' sans configuration"
            );
        }
    }
}
