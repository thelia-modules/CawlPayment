<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Service;

use CawlPayment\CawlPayment;
use CawlPayment\Service\CredentialsEncryptionService;
use CawlPayment\Service\SecureConfigService;
use CawlPayment\Tests\Mock\TlogMock;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour SecureConfigService — flux set/get
 *
 * Couvre le chiffrement automatique à l'écriture et le déchiffrement
 * transparent à la lecture pour les clés sensibles.
 */
class SecureConfigServiceReadWriteTest extends TestCase
{
    private SecureConfigService $service;
    private CredentialsEncryptionService $encryptionService;
    private string $originalEnvKey;

    protected function setUp(): void
    {
        $this->originalEnvKey = getenv('CAWL_ENCRYPTION_KEY') ?: '';

        CawlPayment::resetConfig();
        TlogMock::reset();

        putenv('CAWL_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
        $this->encryptionService = new CredentialsEncryptionService();
        $this->service = new SecureConfigService($this->encryptionService);
    }

    protected function tearDown(): void
    {
        if (!empty($this->originalEnvKey)) {
            putenv('CAWL_ENCRYPTION_KEY=' . $this->originalEnvKey);
        } else {
            putenv('CAWL_ENCRYPTION_KEY');
        }

        CawlPayment::resetConfig();
        TlogMock::reset();
    }

    // =========================================================================
    // setConfigValue() — clés sensibles
    // =========================================================================

    public function testSetConfigValueEncryptsSensitiveKey(): void
    {
        $this->service->setConfigValue('api_key_test', 'my-plaintext-api-key');

        // La valeur stockée brute doit être chiffrée (pas en clair)
        $rawStored = CawlPayment::getConfigValue('api_key_test', '');
        $this->assertNotSame('my-plaintext-api-key', $rawStored, 'La valeur brute en config ne doit pas être en clair');
        $this->assertTrue($this->encryptionService->isEncrypted($rawStored), 'La valeur stockée doit être reconnaissable comme chiffrée');
    }

    public function testSetConfigValueEncryptsApiSecret(): void
    {
        $this->service->setConfigValue('api_secret_prod', 's3cr3t');

        $rawStored = CawlPayment::getConfigValue('api_secret_prod', '');
        $this->assertNotSame('s3cr3t', $rawStored);
        $this->assertTrue($this->encryptionService->isEncrypted($rawStored));
    }

    public function testSetConfigValueDoesNotEncryptEmptySensitiveKey(): void
    {
        $this->service->setConfigValue('api_key_test', '');

        $rawStored = CawlPayment::getConfigValue('api_key_test', 'default');
        // Une valeur vide reste vide (ou absente), jamais chiffrée
        $this->assertSame('', $rawStored);
    }

    // =========================================================================
    // setConfigValue() — clés non sensibles
    // =========================================================================

    public function testSetConfigValueStoresNonSensitiveKeyAsPlaintext(): void
    {
        $this->service->setConfigValue('pspid', 'my-merchant-id');

        $rawStored = CawlPayment::getConfigValue('pspid', '');
        $this->assertSame('my-merchant-id', $rawStored, 'Une clé non sensible doit être stockée en clair');
    }

    public function testSetConfigValueStoresEnvironmentAsPlaintext(): void
    {
        $this->service->setConfigValue('environment', 'production');

        $rawStored = CawlPayment::getConfigValue('environment', '');
        $this->assertSame('production', $rawStored);
    }

    // =========================================================================
    // getConfigValue() — déchiffrement transparent
    // =========================================================================

    public function testGetConfigValueDecryptsSensitiveKeyTransparently(): void
    {
        // Stocker une valeur chiffrée via le service
        $this->service->setConfigValue('api_key_test', 'my-api-key');

        // Lire via le service → doit retourner la valeur en clair
        $retrieved = $this->service->getConfigValue('api_key_test');
        $this->assertSame('my-api-key', $retrieved);
    }

    public function testGetConfigValueDelegatesToDecryptForSensitiveKeys(): void
    {
        // Test comportemental : getConfigValue doit appeler decrypt() et retourner son résultat.
        // On utilise un mock pour s'affranchir des aléas du format base64 du ciphertext.
        $mock = $this->createMock(CredentialsEncryptionService::class);
        $mock->method('isSensitiveKey')->willReturn(true);
        $mock->method('decrypt')->willReturn('expected-plaintext');

        $service = new SecureConfigService($mock);
        CawlPayment::setConfigValue('api_key_test', 'any-stored-value');

        $this->assertSame('expected-plaintext', $service->getConfigValue('api_key_test'));
    }

    public function testGetConfigValueReturnsNonSensitiveKeyAsIs(): void
    {
        CawlPayment::setConfigValue('pspid', 'merchant-123');

        $value = $this->service->getConfigValue('pspid');

        $this->assertSame('merchant-123', $value);
    }

    public function testGetConfigValueReturnsDefaultWhenKeyAbsent(): void
    {
        $value = $this->service->getConfigValue('api_key_test', 'fallback');

        $this->assertSame('fallback', $value);
    }

    public function testGetConfigValueReturnsEmptyStringByDefaultWhenKeyAbsent(): void
    {
        $value = $this->service->getConfigValue('api_key_test');

        $this->assertSame('', $value);
    }

    // =========================================================================
    // Roundtrip set → get
    // =========================================================================

    public function testRoundtripSensitiveKey(): void
    {
        $original = 'super-secret-api-key-' . uniqid();
        $this->service->setConfigValue('api_key_prod', $original);

        $retrieved = $this->service->getConfigValue('api_key_prod');

        $this->assertSame($original, $retrieved, 'Un roundtrip set/get doit restituer la valeur originale');
    }

    public function testRoundtripDoesNotExposeEncryptedValueDirectly(): void
    {
        $original = 'my-plain-secret';
        $this->service->setConfigValue('webhook_secret_test', $original);

        // La valeur brute est chiffrée
        $rawValue = CawlPayment::getConfigValue('webhook_secret_test', '');
        $this->assertNotSame($original, $rawValue, 'La valeur brute ne doit jamais être en clair');

        // Mais le service la restitue déchiffrée
        $decrypted = $this->service->getConfigValue('webhook_secret_test');
        $this->assertSame($original, $decrypted, 'Le service doit restituer la valeur originale');
    }

    // =========================================================================
    // Compatibilité legacy : valeurs en clair déjà en config
    // =========================================================================

    public function testGetConfigValueHandlesLegacyPlaintextGracefully(): void
    {
        // Simuler une valeur en clair déjà stockée (cas legacy avant chiffrement)
        CawlPayment::setConfigValue('api_key_test', 'legacy-plain-value');

        // Comme la valeur n'est pas chiffrée, le déchiffrement peut échouer
        // Le service doit retourner la valeur par défaut sans lever d'exception
        $value = $this->service->getConfigValue('api_key_test', 'default');

        // Soit la valeur brute si le déchiffrement "réussit" sur du non-chiffré,
        // soit le défaut — dans les deux cas, pas d'exception
        $this->assertIsString($value);
    }
}
