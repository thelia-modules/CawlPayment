<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Service;

use CawlPayment\Service\CsrfTokenService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Tests unitaires pour CsrfTokenService
 *
 * Couvre la generation, validation et securite des tokens CSRF
 * utilises pour proteger les formulaires d'administration.
 */
class CsrfTokenServiceTest extends TestCase
{
    private CsrfTokenService $service;
    private Session $session;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);

        $this->requestStack = new RequestStack();
        $this->requestStack->push($request);

        $this->service = new CsrfTokenService($this->requestStack);
    }

    // =========================================================================
    // Tests de generation de token
    // =========================================================================

    public function testGenerateTokenReturns64CharHexString(): void
    {
        $token = $this->service->generateToken();

        $this->assertSame(64, strlen($token), 'Le token doit avoir 64 caracteres (32 bytes en hex)');
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $token, 'Le token doit etre une chaine hexadecimale');
    }

    public function testGenerateTokenStoresInSession(): void
    {
        $token = $this->service->generateToken();

        $this->assertSame(
            $token,
            $this->session->get('cawlpayment_token'),
            'Le token doit etre stocke en session sous la cle cawlpayment_token'
        );
    }

    public function testGenerateTokenProducesUniqueValues(): void
    {
        $tokens = [];
        for ($i = 0; $i < 100; $i++) {
            $tokens[] = $this->service->generateToken();
        }

        $uniqueTokens = array_unique($tokens);
        $this->assertCount(
            100,
            $uniqueTokens,
            'Chaque appel a generateToken() doit produire un token unique'
        );
    }

    public function testGenerateTokenOverwritesPreviousToken(): void
    {
        $token1 = $this->service->generateToken();
        $token2 = $this->service->generateToken();

        $this->assertNotSame($token1, $token2, 'Les tokens successifs doivent etre differents');
        $this->assertSame(
            $token2,
            $this->session->get('cawlpayment_token'),
            'Le nouveau token doit remplacer l\'ancien en session'
        );
    }

    // =========================================================================
    // Tests de validation de token
    // =========================================================================

    public function testValidateTokenReturnsTrueForValidToken(): void
    {
        $token = $this->service->generateToken();

        $this->assertTrue(
            $this->service->validateToken($token),
            'Un token valide doit etre accepte'
        );
    }

    public function testValidateTokenReturnsFalseForInvalidToken(): void
    {
        $this->service->generateToken();

        $this->assertFalse(
            $this->service->validateToken('invalid_token_value'),
            'Un token invalide doit etre rejete'
        );
    }

    public function testValidateTokenReturnsFalseForNullToken(): void
    {
        $this->service->generateToken();

        $this->assertFalse(
            $this->service->validateToken(null),
            'Un token null doit etre rejete'
        );
    }

    public function testValidateTokenReturnsFalseForEmptyToken(): void
    {
        $this->service->generateToken();

        $this->assertFalse(
            $this->service->validateToken(''),
            'Un token vide doit etre rejete'
        );
    }

    public function testValidateTokenWithoutStoredTokenReturnsFalse(): void
    {
        // Pas de token genere au prealable
        $this->assertFalse(
            $this->service->validateToken('any_token_value'),
            'La validation doit echouer si aucun token n\'est stocke en session'
        );
    }

    // =========================================================================
    // Tests de securite (one-time use)
    // =========================================================================

    public function testTokenIsRemovedAfterSuccessfulValidation(): void
    {
        $token = $this->service->generateToken();

        $this->assertTrue($this->service->validateToken($token), 'Premiere validation doit reussir');
        $this->assertFalse(
            $this->service->validateToken($token),
            'Seconde validation du meme token doit echouer (one-time use)'
        );
    }

    public function testTokenRemainsAfterFailedValidation(): void
    {
        $token = $this->service->generateToken();

        // Tentative de validation avec un mauvais token
        $this->assertFalse($this->service->validateToken('wrong_token'));

        // Le token original doit toujours etre valide
        $this->assertTrue(
            $this->service->validateToken($token),
            'Le token doit rester valide apres une tentative de validation echouee'
        );
    }

    public function testTokenIsNotRemovedOnFailedValidation(): void
    {
        $token = $this->service->generateToken();

        // Plusieurs tentatives avec de mauvais tokens
        $this->service->validateToken('wrong1');
        $this->service->validateToken('wrong2');
        $this->service->validateToken('');
        $this->service->validateToken(null);

        // Le token original doit toujours etre valide
        $this->assertTrue(
            $this->service->validateToken($token),
            'Le token doit survivre a plusieurs tentatives de validation echouees'
        );
    }

    // =========================================================================
    // Tests de cas limites
    // =========================================================================

    public function testValidateTokenWithPartialMatch(): void
    {
        $token = $this->service->generateToken();
        $partialToken = substr($token, 0, 32); // Moitie du token

        $this->assertFalse(
            $this->service->validateToken($partialToken),
            'Un token partiel doit etre rejete'
        );
    }

    public function testValidateTokenWithExtraCharacters(): void
    {
        $token = $this->service->generateToken();
        $extendedToken = $token . 'extra';

        $this->assertFalse(
            $this->service->validateToken($extendedToken),
            'Un token avec des caracteres supplementaires doit etre rejete'
        );
    }

    public function testValidateTokenIsCaseSensitive(): void
    {
        $token = $this->service->generateToken();
        $upperToken = strtoupper($token);

        // Les tokens hex peuvent etre sensibles a la casse selon l'implementation
        // Dans ce cas, bin2hex produit des minuscules
        if ($token !== $upperToken) {
            $this->assertFalse(
                $this->service->validateToken($upperToken),
                'La validation doit etre sensible a la casse'
            );
        }
    }

    public function testValidateTokenWithWhitespace(): void
    {
        $token = $this->service->generateToken();

        $this->assertFalse(
            $this->service->validateToken(' ' . $token),
            'Un token avec espaces au debut doit etre rejete'
        );

        $this->assertFalse(
            $this->service->validateToken($token . ' '),
            'Un token avec espaces a la fin doit etre rejete'
        );
    }

    // =========================================================================
    // Tests de gestion de session
    // =========================================================================

    public function testThrowsExceptionWithoutSession(): void
    {
        // Creer un request sans session
        $requestWithoutSession = new Request();
        $requestStack = new RequestStack();
        $requestStack->push($requestWithoutSession);

        $service = new CsrfTokenService($requestStack);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No session available');

        $service->generateToken();
    }

    public function testThrowsExceptionWithoutRequest(): void
    {
        // RequestStack vide
        $emptyRequestStack = new RequestStack();
        $service = new CsrfTokenService($emptyRequestStack);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No session available');

        $service->generateToken();
    }

    public function testValidateTokenThrowsExceptionWithoutSession(): void
    {
        $emptyRequestStack = new RequestStack();
        $service = new CsrfTokenService($emptyRequestStack);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No session available');

        $service->validateToken('some_token');
    }
}
