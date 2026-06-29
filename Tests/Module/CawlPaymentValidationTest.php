<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Module;

use CawlPayment\CawlPayment;
use CawlPayment\Tests\Mock\TlogMock;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour CawlPayment::isValidPayment()
 *
 * Couvre les conditions de garde qui déterminent si le module
 * peut être proposé en paiement (config manquante, méthodes, montants).
 */
class CawlPaymentValidationTest extends TestCase
{
    private CawlPayment $module;
    private string $originalEnvKey;

    protected function setUp(): void
    {
        $this->originalEnvKey = getenv('CAWL_ENCRYPTION_KEY') ?: '';
        putenv('CAWL_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));

        CawlPayment::resetConfig();
        TlogMock::reset();

        // Reset du singleton CredentialsEncryptionService dans CawlPayment
        $ref = new \ReflectionClass(CawlPayment::class);
        $prop = $ref->getProperty('encryptionService');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->module = new CawlPayment();
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

        $ref = new \ReflectionClass(CawlPayment::class);
        $prop = $ref->getProperty('encryptionService');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // =========================================================================
    // Config manquante
    // =========================================================================

    public function testIsValidPaymentReturnsFalseWhenPspidIsEmpty(): void
    {
        $this->setFullConfig();
        CawlPayment::setConfigValue('pspid', '');

        $this->assertFalse($this->module->isValidPayment());
    }

    public function testIsValidPaymentReturnsFalseWhenApiKeyIsEmpty(): void
    {
        $this->setFullConfig();
        CawlPayment::setConfigValue('api_key_test', '');

        $this->assertFalse($this->module->isValidPayment());
    }

    public function testIsValidPaymentReturnsFalseWhenApiSecretIsEmpty(): void
    {
        $this->setFullConfig();
        CawlPayment::setConfigValue('api_secret_test', '');

        $this->assertFalse($this->module->isValidPayment());
    }

    // =========================================================================
    // Méthodes de paiement
    // =========================================================================

    public function testIsValidPaymentReturnsFalseWhenNoEnabledMethods(): void
    {
        $this->setFullConfig();
        CawlPayment::setConfigValue('enabled_methods', '');

        $this->assertFalse($this->module->isValidPayment());
    }

    // =========================================================================
    // Montant minimum/maximum
    // =========================================================================

    public function testIsValidPaymentReturnsFalseWhenAmountBelowMinimum(): void
    {
        $this->setFullConfig();
        CawlPayment::setConfigValue('min_amount', '50');
        CawlPayment::setCurrentOrderAmount(10.0);

        $this->assertFalse($this->module->isValidPayment());
    }

    public function testIsValidPaymentReturnsFalseWhenAmountAboveMaximum(): void
    {
        $this->setFullConfig();
        CawlPayment::setConfigValue('max_amount', '100');
        CawlPayment::setCurrentOrderAmount(200.0);

        $this->assertFalse($this->module->isValidPayment());
    }

    public function testIsValidPaymentReturnsTrueWhenAmountWithinBounds(): void
    {
        $this->setFullConfig();
        CawlPayment::setConfigValue('min_amount', '10');
        CawlPayment::setConfigValue('max_amount', '500');
        CawlPayment::setCurrentOrderAmount(100.0);

        $this->assertTrue($this->module->isValidPayment());
    }

    public function testIsValidPaymentIgnoresMinAmountWhenSetToZero(): void
    {
        $this->setFullConfig();
        CawlPayment::setConfigValue('min_amount', '0');
        CawlPayment::setCurrentOrderAmount(0.01);

        $this->assertTrue($this->module->isValidPayment());
    }

    // =========================================================================
    // Config complète → valide
    // =========================================================================

    public function testIsValidPaymentReturnsTrueWithFullValidConfig(): void
    {
        $this->setFullConfig();

        $this->assertTrue($this->module->isValidPayment());
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function setFullConfig(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);
        CawlPayment::setConfigValue('pspid', 'TEST-MERCHANT');
        // Valeurs plaintext : decrypt échoue silencieusement et retourne la valeur brute
        CawlPayment::setConfigValue('api_key_test', 'plain-api-key');
        CawlPayment::setConfigValue('api_secret_test', 'plain-api-secret');
        CawlPayment::setConfigValue('enabled_methods', 'visa,mastercard');
        CawlPayment::setCurrentOrderAmount(100.0);
    }
}
