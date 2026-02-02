<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Service;

use CawlPayment\Service\IpWhitelistService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests unitaires pour IpWhitelistService
 *
 * Couvre la validation des adresses IP, le matching CIDR,
 * et la gestion de la whitelist pour les webhooks.
 */
class IpWhitelistServiceTest extends TestCase
{
    private IpWhitelistService $service;

    protected function setUp(): void
    {
        $this->service = new IpWhitelistService();
    }

    // =========================================================================
    // Tests de correspondance IP exacte (via reflection)
    // =========================================================================

    public function testExactIpMatch(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        $this->assertTrue(
            $method->invoke($this->service, '192.168.1.1', '192.168.1.1'),
            'Une IP doit correspondre a elle-meme'
        );

        $this->assertFalse(
            $method->invoke($this->service, '192.168.1.2', '192.168.1.1'),
            'Des IPs differentes ne doivent pas correspondre'
        );
    }

    public function testExactIpMatchWithDifferentOctets(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        // Premiere octet different
        $this->assertFalse($method->invoke($this->service, '10.168.1.1', '192.168.1.1'));

        // Deuxieme octet different
        $this->assertFalse($method->invoke($this->service, '192.0.1.1', '192.168.1.1'));

        // Troisieme octet different
        $this->assertFalse($method->invoke($this->service, '192.168.0.1', '192.168.1.1'));

        // Quatrieme octet different
        $this->assertFalse($method->invoke($this->service, '192.168.1.0', '192.168.1.1'));
    }

    // =========================================================================
    // Tests de correspondance CIDR
    // =========================================================================

    public function testCidrMatching24Network(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        // /24 network (256 adresses: .0 a .255)
        $this->assertTrue(
            $method->invoke($this->service, '192.168.1.1', '192.168.1.0/24'),
            'IP doit correspondre a son reseau /24'
        );

        $this->assertTrue(
            $method->invoke($this->service, '192.168.1.255', '192.168.1.0/24'),
            'Derniere IP du reseau /24 doit correspondre'
        );

        $this->assertTrue(
            $method->invoke($this->service, '192.168.1.0', '192.168.1.0/24'),
            'Premiere IP du reseau /24 doit correspondre'
        );

        $this->assertFalse(
            $method->invoke($this->service, '192.168.2.1', '192.168.1.0/24'),
            'IP hors du reseau /24 ne doit pas correspondre'
        );
    }

    public function testCidrMatching16Network(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        // /16 network (65536 adresses)
        $this->assertTrue(
            $method->invoke($this->service, '10.0.0.1', '10.0.0.0/16'),
            'IP doit correspondre a son reseau /16'
        );

        $this->assertTrue(
            $method->invoke($this->service, '10.0.255.255', '10.0.0.0/16'),
            'Derniere IP du reseau /16 doit correspondre'
        );

        $this->assertTrue(
            $method->invoke($this->service, '10.0.5.10', '10.0.0.0/16'),
            'IP au milieu du reseau /16 doit correspondre'
        );

        $this->assertFalse(
            $method->invoke($this->service, '10.1.0.1', '10.0.0.0/16'),
            'IP hors du reseau /16 ne doit pas correspondre'
        );
    }

    public function testCidrMatching8Network(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        // /8 network (16 millions d'adresses)
        $this->assertTrue(
            $method->invoke($this->service, '10.0.0.1', '10.0.0.0/8'),
            'IP doit correspondre a son reseau /8'
        );

        $this->assertTrue(
            $method->invoke($this->service, '10.255.255.255', '10.0.0.0/8'),
            'Derniere IP du reseau /8 doit correspondre'
        );

        $this->assertFalse(
            $method->invoke($this->service, '11.0.0.1', '10.0.0.0/8'),
            'IP hors du reseau /8 ne doit pas correspondre'
        );
    }

    public function testCidrMatchingEdgeCases(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        // /32 - une seule adresse
        $this->assertTrue(
            $method->invoke($this->service, '192.168.1.100', '192.168.1.100/32'),
            '/32 doit correspondre exactement a une seule IP'
        );

        $this->assertFalse(
            $method->invoke($this->service, '192.168.1.101', '192.168.1.100/32'),
            '/32 ne doit pas correspondre a une IP differente'
        );

        // /0 - toutes les adresses (masque 0)
        $this->assertTrue(
            $method->invoke($this->service, '192.168.1.1', '0.0.0.0/0'),
            '/0 doit correspondre a toutes les IPs'
        );

        $this->assertTrue(
            $method->invoke($this->service, '255.255.255.255', '0.0.0.0/0'),
            '/0 doit correspondre a toutes les IPs'
        );
    }

    // =========================================================================
    // Tests avec des entrees invalides
    // =========================================================================

    public function testInvalidIpReturnsFalse(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        $this->assertFalse(
            $method->invoke($this->service, 'invalid', '192.168.1.0/24'),
            'Une IP invalide ne doit pas correspondre'
        );

        $this->assertFalse(
            $method->invoke($this->service, '192.168.1.1', 'invalid/24'),
            'Un CIDR avec IP invalide ne doit pas correspondre'
        );
    }

    /**
     * Note: Un masque non-numerique comme 'invalid' est cast en 0 par PHP,
     * ce qui equivaut a /0 (match toutes les IPs). Ce test documente ce comportement.
     * Si ce n'est pas le comportement souhaite, le service devrait etre modifie
     * pour valider que le masque est numerique avant le cast.
     */
    public function testNonNumericCidrMaskIsCastToZero(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        // (int) 'invalid' === 0, donc /invalid devient /0 et match toutes les IPs
        $this->assertTrue(
            $method->invoke($this->service, '192.168.1.1', '192.168.1.0/invalid'),
            'Un masque non-numerique est cast en 0 (match tout) - comportement actuel'
        );
    }

    public function testEmptyValuesReturnFalse(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        $this->assertFalse(
            $method->invoke($this->service, '', '192.168.1.0/24'),
            'Une IP vide ne doit pas correspondre'
        );

        $this->assertFalse(
            $method->invoke($this->service, '192.168.1.1', ''),
            'Un CIDR vide ne doit pas correspondre'
        );
    }

    public function testInvalidCidrMaskReturnsFalse(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        // Masque hors limites
        $this->assertFalse(
            $method->invoke($this->service, '192.168.1.1', '192.168.1.0/33'),
            'Un masque > 32 ne doit pas etre valide'
        );

        $this->assertFalse(
            $method->invoke($this->service, '192.168.1.1', '192.168.1.0/-1'),
            'Un masque negatif ne doit pas etre valide'
        );
    }

    public function testMalformedCidrReturnsFalse(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        // Multiple slashes
        $this->assertFalse(
            $method->invoke($this->service, '192.168.1.1', '192.168.1.0/24/16'),
            'Un CIDR avec plusieurs slashes ne doit pas etre valide'
        );
    }

    // =========================================================================
    // Tests de validation de liste d'IPs
    // =========================================================================

    public function testValidateIpListWithValidIps(): void
    {
        $result = $this->service->validateIpList('192.168.1.1, 10.0.0.1, 172.16.0.1');

        $this->assertTrue($result['valid'], 'Une liste d\'IPs valides doit etre validee');
        $this->assertEmpty($result['errors'], 'Il ne doit pas y avoir d\'erreurs');
    }

    public function testValidateIpListWithValidCidrs(): void
    {
        $result = $this->service->validateIpList('192.168.1.0/24, 10.0.0.0/8, 172.16.0.0/12');

        $this->assertTrue($result['valid'], 'Une liste de CIDRs valides doit etre validee');
        $this->assertEmpty($result['errors'], 'Il ne doit pas y avoir d\'erreurs');
    }

    public function testValidateIpListWithMixedValidAndInvalid(): void
    {
        $result = $this->service->validateIpList('192.168.1.1, invalid, 10.0.0.0/8, not-an-ip, 172.16.0.0/12');

        $this->assertFalse($result['valid'], 'Une liste avec des entrees invalides ne doit pas etre validee');
        $this->assertCount(2, $result['errors'], 'Il doit y avoir 2 erreurs');
    }

    public function testValidateIpListWithInvalidEntries(): void
    {
        $result = $this->service->validateIpList('invalid, not-an-ip, 999.999.999.999');

        $this->assertFalse($result['valid'], 'Une liste d\'entrees invalides ne doit pas etre validee');
        $this->assertCount(3, $result['errors'], 'Chaque entree invalide doit generer une erreur');
    }

    public function testValidateIpListWithEmptyString(): void
    {
        $result = $this->service->validateIpList('');

        $this->assertTrue($result['valid'], 'Une liste vide doit etre consideree comme valide');
        $this->assertEmpty($result['errors'], 'Il ne doit pas y avoir d\'erreurs');
    }

    public function testValidateIpListWithWhitespace(): void
    {
        $result = $this->service->validateIpList('  192.168.1.1  ,   10.0.0.0/24  ');

        $this->assertTrue($result['valid'], 'Les espaces doivent etre ignores');
        $this->assertEmpty($result['errors'], 'Il ne doit pas y avoir d\'erreurs');
    }

    // =========================================================================
    // Tests des adresses IP specifiques
    // =========================================================================

    public function testLoopbackAddress(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        $this->assertTrue(
            $method->invoke($this->service, '127.0.0.1', '127.0.0.1'),
            'L\'adresse loopback doit correspondre'
        );

        $this->assertTrue(
            $method->invoke($this->service, '127.0.0.1', '127.0.0.0/8'),
            'L\'adresse loopback doit etre dans le reseau 127.0.0.0/8'
        );
    }

    public function testPrivateNetworkRanges(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        // Classe A privee (10.0.0.0/8)
        $this->assertTrue($method->invoke($this->service, '10.255.255.255', '10.0.0.0/8'));

        // Classe B privee (172.16.0.0/12)
        $this->assertTrue($method->invoke($this->service, '172.16.0.1', '172.16.0.0/12'));
        $this->assertTrue($method->invoke($this->service, '172.31.255.255', '172.16.0.0/12'));
        $this->assertFalse($method->invoke($this->service, '172.32.0.1', '172.16.0.0/12'));

        // Classe C privee (192.168.0.0/16)
        $this->assertTrue($method->invoke($this->service, '192.168.0.1', '192.168.0.0/16'));
        $this->assertTrue($method->invoke($this->service, '192.168.255.255', '192.168.0.0/16'));
    }

    public function testBroadcastAndNetworkAddresses(): void
    {
        $method = $this->getIpMatchesCidrMethod();

        // Adresse reseau
        $this->assertTrue(
            $method->invoke($this->service, '192.168.1.0', '192.168.1.0/24'),
            'L\'adresse reseau doit correspondre au CIDR'
        );

        // Adresse broadcast
        $this->assertTrue(
            $method->invoke($this->service, '192.168.1.255', '192.168.1.0/24'),
            'L\'adresse broadcast doit correspondre au CIDR'
        );
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Retourne la methode privee ipMatchesCidr pour les tests
     */
    private function getIpMatchesCidrMethod(): ReflectionMethod
    {
        $method = new ReflectionMethod(IpWhitelistService::class, 'ipMatchesCidr');
        $method->setAccessible(true);
        return $method;
    }
}
