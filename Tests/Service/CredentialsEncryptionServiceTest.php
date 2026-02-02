<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Service;

use CawlPayment\Service\CredentialsEncryptionService;
use CawlPayment\Tests\Mock\ConfigQueryMock;
use CawlPayment\Tests\Mock\TlogMock;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour CredentialsEncryptionService
 *
 * Couvre le chiffrement/dechiffrement AES-256-GCM des credentials sensibles,
 * la detection des valeurs legacy, et la gestion des cles de chiffrement.
 */
class CredentialsEncryptionServiceTest extends TestCase
{
    private CredentialsEncryptionService $service;
    private string $originalEnvKey;

    protected function setUp(): void
    {
        // Sauvegarder la valeur originale
        $this->originalEnvKey = getenv('CAWL_ENCRYPTION_KEY') ?: '';

        // Reinitialiser les mocks
        ConfigQueryMock::reset();
        TlogMock::reset();

        // Definir une cle de chiffrement de test (32 bytes en base64)
        $testKey = base64_encode(random_bytes(32));
        putenv('CAWL_ENCRYPTION_KEY=' . $testKey);

        $this->service = new CredentialsEncryptionService();
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
    }

    // =========================================================================
    // Tests de chiffrement
    // =========================================================================

    public function testEncryptReturnsBase64EncodedString(): void
    {
        $encrypted = $this->service->encrypt('test_secret_value');

        $this->assertNotEmpty($encrypted, 'Le resultat chiffre ne doit pas etre vide');
        $this->assertNotEquals('test_secret_value', $encrypted, 'La valeur chiffree doit differer de l\'originale');

        // Verifier que c'est du base64 valide
        $decoded = base64_decode($encrypted, true);
        $this->assertNotFalse($decoded, 'La valeur chiffree doit etre encodee en base64 valide');
    }

    public function testEncryptEmptyStringReturnsEmpty(): void
    {
        $result = $this->service->encrypt('');

        $this->assertSame('', $result, 'Le chiffrement d\'une chaine vide doit retourner une chaine vide');
    }

    public function testEncryptProducesDifferentOutputsForSameInput(): void
    {
        $input = 'test_api_key_value';

        $encrypted1 = $this->service->encrypt($input);
        $encrypted2 = $this->service->encrypt($input);

        // Due au IV aleatoire, les valeurs chiffrees doivent differer
        $this->assertNotEquals(
            $encrypted1,
            $encrypted2,
            'Deux chiffrements de la meme valeur doivent produire des resultats differents (IV aleatoire)'
        );
    }

    public function testEncryptHandlesSpecialCharacters(): void
    {
        $specialValues = [
            'test@#$%^&*()',
            "value\twith\ttabs",
            "value\nwith\nnewlines",
            'unicode: eaeiu',
            str_repeat('long_', 100), // Valeur longue
        ];

        foreach ($specialValues as $value) {
            $encrypted = $this->service->encrypt($value);
            $decrypted = $this->service->decrypt($encrypted);

            $this->assertSame(
                $value,
                $decrypted,
                'Les caracteres speciaux doivent etre preserves apres chiffrement/dechiffrement'
            );
        }
    }

    public function testEncryptHandlesLongValues(): void
    {
        $longValue = str_repeat('a', 10000);

        $encrypted = $this->service->encrypt($longValue);
        $decrypted = $this->service->decrypt($encrypted);

        $this->assertSame($longValue, $decrypted, 'Les valeurs longues doivent etre traitees correctement');
    }

    // =========================================================================
    // Tests de dechiffrement
    // =========================================================================

    public function testDecryptReturnsOriginalValue(): void
    {
        $original = 'my_api_secret_key_12345';

        $encrypted = $this->service->encrypt($original);
        $decrypted = $this->service->decrypt($encrypted);

        $this->assertSame($original, $decrypted, 'Le dechiffrement doit retourner la valeur originale');
    }

    public function testDecryptEmptyStringReturnsEmpty(): void
    {
        $result = $this->service->decrypt('');

        $this->assertSame('', $result, 'Le dechiffrement d\'une chaine vide doit retourner une chaine vide');
    }

    public function testDecryptLegacyPlainTextReturnsAsIs(): void
    {
        // Les valeurs legacy (non chiffrees) doivent etre retournees telles quelles
        $plainTextValues = [
            'simple_api_key',
            'sk_test_1234567890',
            'pk_live_abcdefghij',
            'api_key_value_here',
        ];

        foreach ($plainTextValues as $plainText) {
            $result = $this->service->decrypt($plainText);
            $this->assertSame(
                $plainText,
                $result,
                "La valeur legacy '$plainText' doit etre retournee sans modification"
            );
        }
    }

    public function testDecryptWithInvalidBase64ThrowsException(): void
    {
        // Une valeur qui ressemble a du base64 chiffre mais qui est corrompue
        // Creer une valeur qui passe isEncrypted() mais qui est invalide
        $fakeEncrypted = base64_encode(random_bytes(50));

        // La valeur devrait echouer au dechiffrement car elle n'a pas ete chiffree correctement
        $this->expectException(\RuntimeException::class);
        $this->service->decrypt($fakeEncrypted);
    }

    // =========================================================================
    // Tests de detection de valeur chiffree
    // =========================================================================

    public function testIsEncryptedReturnsTrueForEncryptedValue(): void
    {
        $encrypted = $this->service->encrypt('test_value');

        $this->assertTrue(
            $this->service->isEncrypted($encrypted),
            'Une valeur chiffree doit etre detectee comme telle'
        );
    }

    public function testIsEncryptedReturnsFalseForPlainApiKeys(): void
    {
        $plainApiKeys = [
            'sk_test_1234567890abcdef',
            'pk_live_abcdefghij1234567890',
            'api_my_key_value',
            'key_test_12345',
            'secret_abc123',
            'wh_webhook_secret',
        ];

        foreach ($plainApiKeys as $apiKey) {
            $this->assertFalse(
                $this->service->isEncrypted($apiKey),
                "L'API key '$apiKey' ne doit pas etre detectee comme chiffree"
            );
        }
    }

    public function testIsEncryptedReturnsFalseForShortValues(): void
    {
        $this->assertFalse(
            $this->service->isEncrypted('short'),
            'Une valeur trop courte ne doit pas etre detectee comme chiffree'
        );
    }

    public function testIsEncryptedReturnsFalseForEmptyString(): void
    {
        $this->assertFalse(
            $this->service->isEncrypted(''),
            'Une chaine vide ne doit pas etre detectee comme chiffree'
        );
    }

    // =========================================================================
    // Tests d'identification des cles sensibles
    // =========================================================================

    public function testIsSensitiveKeyReturnsTrueForApiKeys(): void
    {
        $sensitiveKeys = [
            'api_key_test',
            'api_key_prod',
            'api_secret_test',
            'api_secret_prod',
            'webhook_key_test',
            'webhook_key_prod',
            'webhook_secret_test',
            'webhook_secret_prod',
        ];

        foreach ($sensitiveKeys as $key) {
            $this->assertTrue(
                $this->service->isSensitiveKey($key),
                "La cle '$key' doit etre identifiee comme sensible"
            );
        }
    }

    public function testIsSensitiveKeyReturnsFalseForNonSensitiveKeys(): void
    {
        $nonSensitiveKeys = [
            'pspid',
            'environment',
            'enabled_methods',
            'debug_mode',
            'webhook_url',
            'store_name',
            'currency',
        ];

        foreach ($nonSensitiveKeys as $key) {
            $this->assertFalse(
                $this->service->isSensitiveKey($key),
                "La cle '$key' ne doit pas etre identifiee comme sensible"
            );
        }
    }

    // =========================================================================
    // Tests de la liste des cles sensibles
    // =========================================================================

    public function testGetSensitiveKeysReturnsExpectedKeys(): void
    {
        $keys = $this->service->getSensitiveKeys();

        $this->assertIsArray($keys, 'getSensitiveKeys() doit retourner un array');
        $this->assertNotEmpty($keys, 'La liste des cles sensibles ne doit pas etre vide');

        // Verifier que les cles principales sont presentes
        $expectedKeys = ['api_key_test', 'api_key_prod', 'api_secret_test', 'api_secret_prod'];
        foreach ($expectedKeys as $expectedKey) {
            $this->assertContains(
                $expectedKey,
                $keys,
                "La cle '$expectedKey' doit etre dans la liste des cles sensibles"
            );
        }
    }

    // =========================================================================
    // Tests de symetrie chiffrement/dechiffrement
    // =========================================================================

    public function testEncryptDecryptSymmetry(): void
    {
        $testValues = [
            'simple',
            'with spaces',
            'with-dashes_and_underscores',
            '12345678901234567890',
            'MixedCase123ABC',
            json_encode(['key' => 'value']),
        ];

        foreach ($testValues as $value) {
            $encrypted = $this->service->encrypt($value);
            $decrypted = $this->service->decrypt($encrypted);

            $this->assertSame(
                $value,
                $decrypted,
                "La symetrie chiffrement/dechiffrement doit etre preservee pour '$value'"
            );
        }
    }

    public function testMultipleEncryptDecryptCycles(): void
    {
        $value = 'test_credential_value';

        // Premier cycle
        $encrypted1 = $this->service->encrypt($value);
        $decrypted1 = $this->service->decrypt($encrypted1);
        $this->assertSame($value, $decrypted1);

        // Deuxieme cycle avec valeur dechiffree
        $encrypted2 = $this->service->encrypt($decrypted1);
        $decrypted2 = $this->service->decrypt($encrypted2);
        $this->assertSame($value, $decrypted2);

        // Les valeurs chiffrees doivent differer
        $this->assertNotEquals($encrypted1, $encrypted2);
    }

    // =========================================================================
    // Tests de gestion des erreurs
    // =========================================================================

    public function testDecryptWithDifferentKeyFails(): void
    {
        $original = 'secret_value';
        $encrypted = $this->service->encrypt($original);

        // Creer un nouveau service avec une cle differente
        putenv('CAWL_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
        $newService = new CredentialsEncryptionService();

        // Le dechiffrement doit echouer avec une cle differente
        $this->expectException(\RuntimeException::class);
        $newService->decrypt($encrypted);
    }
}
