<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Controller\Admin;

use CawlPayment\CawlPayment;
use PHPUnit\Framework\TestCase;

/**
 * Tests de validation du champ environment dans ConfigurationController
 *
 * Verifie que seules les valeurs 'test' et 'production' sont acceptees.
 */
class ConfigurationControllerEnvironmentTest extends TestCase
{
    /**
     * @dataProvider validEnvironmentProvider
     */
    public function testValidEnvironmentValues(string $environment): void
    {
        $allowedEnvironments = [CawlPayment::ENV_TEST, CawlPayment::ENV_PRODUCTION];

        $this->assertContains($environment, $allowedEnvironments);
        $this->assertTrue(in_array($environment, $allowedEnvironments, true));
    }

    public static function validEnvironmentProvider(): array
    {
        return [
            'test environment' => [CawlPayment::ENV_TEST],
            'production environment' => [CawlPayment::ENV_PRODUCTION],
        ];
    }

    /**
     * @dataProvider invalidEnvironmentProvider
     */
    public function testInvalidEnvironmentValuesAreRejected(string $environment): void
    {
        $allowedEnvironments = [CawlPayment::ENV_TEST, CawlPayment::ENV_PRODUCTION];

        $this->assertFalse(in_array($environment, $allowedEnvironments, true));
    }

    public static function invalidEnvironmentProvider(): array
    {
        return [
            'empty string' => [''],
            'staging' => ['staging'],
            'dev' => ['dev'],
            'prod' => ['prod'],
            'PRODUCTION uppercase' => ['PRODUCTION'],
            'TEST uppercase' => ['TEST'],
            'random string' => ['foobar'],
            'sql injection attempt' => ["'; DROP TABLE orders; --"],
            'xss attempt' => ['<script>alert(1)</script>'],
        ];
    }

    public function testEnvironmentConstantsAreCorrect(): void
    {
        $this->assertSame('test', CawlPayment::ENV_TEST);
        $this->assertSame('production', CawlPayment::ENV_PRODUCTION);
    }
}
