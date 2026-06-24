<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Service;

use CawlPayment\CawlPayment;
use CawlPayment\Service\CawlApiService;
use CawlPayment\Service\CredentialsEncryptionService;
use CawlPayment\Tests\Mock\TlogMock;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour CawlApiService
 *
 * Couvre :
 *  - getEnabledPaymentMethods() : logique de parsing pure
 *  - verifyWebhookSignature()   : sécurité critique HMAC-SHA256
 *  - getActiveEnvironment()     : lecture de config
 *  - getApiUrl()                : sélection d'URL selon l'environnement
 *  - getCheckoutUrl()           : construction d'URL de checkout
 */
class CawlApiServiceTest extends TestCase
{
    private CawlApiService $service;

    protected function setUp(): void
    {
        CawlPayment::resetConfig();
        TlogMock::reset();

        // Le mock retourne toutes les clés comme non-sensibles
        // pour que getDecryptedConfigValue() renvoie la valeur brute
        $encryptionMock = $this->createMock(CredentialsEncryptionService::class);
        $encryptionMock->method('isSensitiveKey')->willReturn(false);
        $encryptionMock->method('decrypt')->willReturnArgument(0);

        $reflection = new \ReflectionClass(CawlApiService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();

        $encProp = $reflection->getProperty('encryptionService');
        $encProp->setAccessible(true);
        $encProp->setValue($this->service, $encryptionMock);
    }

    protected function tearDown(): void
    {
        CawlPayment::resetConfig();
        TlogMock::reset();
    }

    // =========================================================================
    // getEnabledPaymentMethods()
    // =========================================================================

    public function testGetEnabledPaymentMethodsReturnsEmptyArrayWhenNotConfigured(): void
    {
        $result = $this->service->getEnabledPaymentMethods();

        $this->assertSame([], $result);
    }

    public function testGetEnabledPaymentMethodsReturnsEmptyArrayForEmptyConfig(): void
    {
        CawlPayment::setConfigValue('enabled_methods', '');

        $result = $this->service->getEnabledPaymentMethods();

        $this->assertSame([], $result);
    }

    public function testGetEnabledPaymentMethodsResolvesKnownMethodCode(): void
    {
        $knownCode = array_key_first(CawlPayment::PAYMENT_METHODS);
        CawlPayment::setConfigValue('enabled_methods', $knownCode);

        $result = $this->service->getEnabledPaymentMethods();

        $this->assertArrayHasKey($knownCode, $result);
        $this->assertSame(CawlPayment::PAYMENT_METHODS[$knownCode], $result[$knownCode]);
    }

    public function testGetEnabledPaymentMethodsSkipsUnknownCode(): void
    {
        CawlPayment::setConfigValue('enabled_methods', 'unknown_code_xyz');

        $result = $this->service->getEnabledPaymentMethods();

        $this->assertSame([], $result);
    }

    public function testGetEnabledPaymentMethodsResolvesProductPrefixWithValidId(): void
    {
        CawlPayment::setConfigValue('enabled_methods', 'product_1');

        $result = $this->service->getEnabledPaymentMethods();

        $this->assertArrayHasKey('product_1', $result);
        $this->assertSame(1, $result['product_1']['id']);
        $this->assertSame('api', $result['product_1']['category']);
    }

    public function testGetEnabledPaymentMethodsSkipsProductPrefixWithZeroId(): void
    {
        CawlPayment::setConfigValue('enabled_methods', 'product_0');

        $result = $this->service->getEnabledPaymentMethods();

        $this->assertSame([], $result);
    }

    public function testGetEnabledPaymentMethodsHandlesMultipleMethodsCommaSeparated(): void
    {
        $codes = array_slice(array_keys(CawlPayment::PAYMENT_METHODS), 0, 2);
        CawlPayment::setConfigValue('enabled_methods', implode(',', $codes));

        $result = $this->service->getEnabledPaymentMethods();

        $this->assertCount(2, $result);
        foreach ($codes as $code) {
            $this->assertArrayHasKey($code, $result);
        }
    }

    public function testGetEnabledPaymentMethodsTrimsWhitespace(): void
    {
        $code = array_key_first(CawlPayment::PAYMENT_METHODS);
        CawlPayment::setConfigValue('enabled_methods', '  ' . $code . '  ');

        $result = $this->service->getEnabledPaymentMethods();

        $this->assertArrayHasKey($code, $result);
    }

    public function testGetEnabledPaymentMethodsMixesKnownAndProductPrefix(): void
    {
        $knownCode = array_key_first(CawlPayment::PAYMENT_METHODS);
        CawlPayment::setConfigValue('enabled_methods', $knownCode . ',product_42');

        $result = $this->service->getEnabledPaymentMethods();

        $this->assertArrayHasKey($knownCode, $result);
        $this->assertArrayHasKey('product_42', $result);
        $this->assertSame(42, $result['product_42']['id']);
    }

    // =========================================================================
    // verifyWebhookSignature() — via Reflection (méthode privée)
    // =========================================================================

    private function callVerifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $method = new \ReflectionMethod(CawlApiService::class, 'verifyWebhookSignature');
        $method->setAccessible(true);
        return $method->invoke($this->service, $rawBody, $signature);
    }

    public function testVerifyWebhookSignatureAllowsEmptySecretInTestMode(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);
        CawlPayment::setConfigValue('webhook_secret_test', '');

        $result = $this->callVerifyWebhookSignature('{"event":"test"}', '');

        $this->assertTrue($result, 'En mode test sans secret, la signature doit être acceptée');
    }

    public function testVerifyWebhookSignatureRejectsEmptySecretInProductionMode(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_PRODUCTION);
        CawlPayment::setConfigValue('webhook_secret_prod', '');

        $result = $this->callVerifyWebhookSignature('{"event":"test"}', 'any_signature');

        $this->assertFalse($result, 'En production sans secret, tout webhook doit être rejeté');
    }

    public function testVerifyWebhookSignatureRejectsEmptySignatureWhenSecretIsSet(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);
        CawlPayment::setConfigValue('webhook_secret_test', 'my-secret');

        $result = $this->callVerifyWebhookSignature('{"event":"test"}', '');

        $this->assertFalse($result, 'Une signature vide doit être rejetée même en mode test');
    }

    public function testVerifyWebhookSignatureAcceptsValidHmac(): void
    {
        $secret = 'test-webhook-secret';
        $body = '{"payment":{"id":"abc123","status":"CAPTURED"}}';
        $validSignature = base64_encode(hash_hmac('sha256', $body, $secret, true));

        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);
        CawlPayment::setConfigValue('webhook_secret_test', $secret);

        $result = $this->callVerifyWebhookSignature($body, $validSignature);

        $this->assertTrue($result);
    }

    public function testVerifyWebhookSignatureRejectsInvalidHmac(): void
    {
        $secret = 'test-webhook-secret';
        $body = '{"payment":{"id":"abc123","status":"CAPTURED"}}';
        $invalidSignature = base64_encode(hash_hmac('sha256', $body, 'wrong-secret', true));

        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);
        CawlPayment::setConfigValue('webhook_secret_test', $secret);

        $result = $this->callVerifyWebhookSignature($body, $invalidSignature);

        $this->assertFalse($result);
    }

    public function testVerifyWebhookSignatureRejectsTamperedBody(): void
    {
        $secret = 'test-webhook-secret';
        $originalBody = '{"payment":{"id":"abc123","status":"CAPTURED"}}';
        $tamperedBody = '{"payment":{"id":"abc123","status":"PAID"}}';
        $signatureOfOriginal = base64_encode(hash_hmac('sha256', $originalBody, $secret, true));

        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);
        CawlPayment::setConfigValue('webhook_secret_test', $secret);

        $result = $this->callVerifyWebhookSignature($tamperedBody, $signatureOfOriginal);

        $this->assertFalse($result, 'Un corps altéré avec une signature valide de l\'original doit être rejeté');
    }

    public function testVerifyWebhookSignatureUsesProductionSecretInProduction(): void
    {
        $prodSecret = 'prod-webhook-secret';
        $body = '{"event":"payment"}';
        $validProdSignature = base64_encode(hash_hmac('sha256', $body, $prodSecret, true));

        CawlPayment::setConfigValue('environment', CawlPayment::ENV_PRODUCTION);
        CawlPayment::setConfigValue('webhook_secret_prod', $prodSecret);
        CawlPayment::setConfigValue('webhook_secret_test', 'different-test-secret');

        $result = $this->callVerifyWebhookSignature($body, $validProdSignature);

        $this->assertTrue($result, 'La clé de production doit être utilisée en mode production');
    }

    // =========================================================================
    // getActiveEnvironment()
    // =========================================================================

    public function testGetActiveEnvironmentReturnsTestByDefault(): void
    {
        $this->assertSame(CawlPayment::ENV_TEST, $this->service->getActiveEnvironment());
    }

    public function testGetActiveEnvironmentReturnsProductionWhenConfigured(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_PRODUCTION);

        $this->assertSame(CawlPayment::ENV_PRODUCTION, $this->service->getActiveEnvironment());
    }

    // =========================================================================
    // getApiUrl()
    // =========================================================================

    public function testGetApiUrlReturnsPreprodUrlInTestMode(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);

        $this->assertSame(CawlPayment::API_URL_TEST, $this->service->getApiUrl());
    }

    public function testGetApiUrlReturnsProdUrlInProductionMode(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_PRODUCTION);

        $this->assertSame(CawlPayment::API_URL_PROD, $this->service->getApiUrl());
    }

    // =========================================================================
    // getCheckoutUrl()
    // =========================================================================

    public function testGetCheckoutUrlInTestModeContainsPreprodDomain(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);

        $url = $this->service->getCheckoutUrl('checkout-id-123', 'mac-abc');

        $this->assertStringContainsString('preprod', $url);
        $this->assertStringContainsString('checkout-id-123', $url);
        $this->assertStringContainsString('mac-abc', $url);
    }

    public function testGetCheckoutUrlInProductionModeContainsProdDomain(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_PRODUCTION);

        $url = $this->service->getCheckoutUrl('checkout-id-456', 'mac-def');

        $this->assertStringNotContainsString('preprod', $url);
        $this->assertStringContainsString('checkout-id-456', $url);
        $this->assertStringContainsString('mac-def', $url);
    }

    public function testGetCheckoutUrlIncludesHostedCheckoutPath(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);

        $url = $this->service->getCheckoutUrl('hco-789', 'mac-xyz');

        $this->assertStringContainsString('/hostedcheckout/', $url);
    }
}
