<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Service;

use CawlPayment\CawlPayment;
use CawlPayment\Service\IpWhitelistService;
use CawlPayment\Tests\Mock\TlogMock;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour IpWhitelistService::isIpAllowed()
 *
 * Couvre :
 *  - Mode test, whitelist désactivée → toujours autorisé
 *  - Mode prod, whitelist désactivée → toujours autorisé
 *  - Mode prod, whitelist activée, IP présente → autorisé
 *  - Mode prod, whitelist activée, IP absente → refusé
 *  - Mode prod, whitelist activée mais vide → refusé (sécurité par défaut)
 */
class IpWhitelistServicePublicTest extends TestCase
{
    private IpWhitelistService $service;

    protected function setUp(): void
    {
        CawlPayment::resetConfig();
        TlogMock::reset();
        $this->service = new IpWhitelistService();
    }

    protected function tearDown(): void
    {
        CawlPayment::resetConfig();
        TlogMock::reset();
    }

    // =========================================================================
    // Mode test — whitelist désactivée
    // =========================================================================

    public function testIsIpAllowedReturnsTrueInTestModeWithWhitelistDisabled(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_TEST);
        CawlPayment::setConfigValue('webhook_whitelist_enabled', '0');

        $this->assertTrue($this->service->isIpAllowed('1.2.3.4'));
    }

    // =========================================================================
    // Mode prod — whitelist désactivée
    // =========================================================================

    public function testIsIpAllowedReturnsTrueInProductionModeWithWhitelistDisabled(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_PRODUCTION);
        CawlPayment::setConfigValue('webhook_whitelist_enabled', '0');

        $this->assertTrue($this->service->isIpAllowed('5.6.7.8'));
    }

    // =========================================================================
    // Mode prod — whitelist activée
    // =========================================================================

    public function testIsIpAllowedReturnsTrueWhenIpIsInWhitelist(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_PRODUCTION);
        CawlPayment::setConfigValue('webhook_whitelist_enabled', '1');
        CawlPayment::setConfigValue('webhook_ip_whitelist', '192.168.1.10, 10.0.0.0/8');

        $this->assertTrue($this->service->isIpAllowed('192.168.1.10'));
    }

    public function testIsIpAllowedReturnsTrueWhenIpMatchesCidrRange(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_PRODUCTION);
        CawlPayment::setConfigValue('webhook_whitelist_enabled', '1');
        CawlPayment::setConfigValue('webhook_ip_whitelist', '10.0.0.0/8');

        $this->assertTrue($this->service->isIpAllowed('10.255.255.255'));
    }

    public function testIsIpAllowedReturnsFalseWhenIpIsNotInWhitelist(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_PRODUCTION);
        CawlPayment::setConfigValue('webhook_whitelist_enabled', '1');
        CawlPayment::setConfigValue('webhook_ip_whitelist', '192.168.1.10');

        $this->assertFalse($this->service->isIpAllowed('1.2.3.4'));
    }

    public function testIsIpAllowedReturnsFalseWhenWhitelistIsEnabledButEmpty(): void
    {
        CawlPayment::setConfigValue('environment', CawlPayment::ENV_PRODUCTION);
        CawlPayment::setConfigValue('webhook_whitelist_enabled', '1');
        CawlPayment::setConfigValue('webhook_ip_whitelist', '');

        $this->assertFalse($this->service->isIpAllowed('1.2.3.4'));
    }
}
