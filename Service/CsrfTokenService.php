<?php

declare(strict_types=1);

namespace CawlPayment\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service de gestion des tokens CSRF pour la protection des formulaires
 * 
 * Ce service genere et valide les tokens CSRF pour prevenir les attaques
 * Cross-Site Request Forgery sur les formulaires d'administration.
 */
class CsrfTokenService
{
    private const TOKEN_NAME = 'cawlpayment_token';

    public function __construct(
        private readonly RequestStack $requestStack
    ) {
    }

    /**
     * Genere un nouveau token CSRF et le stocke en session
     *
     * @return string Le token genere
     * @throws \RuntimeException Si aucune session n'est disponible
     */
    public function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->getSession()->set(self::TOKEN_NAME, $token);

        return $token;
    }

    /**
     * Valide un token CSRF soumis
     *
     * Utilise hash_equals() pour prevenir les timing attacks.
     * Le token est a usage unique : il est supprime apres une validation reussie.
     *
     * @param string|null $submittedToken Le token soumis par le formulaire
     * @return bool True si le token est valide, false sinon
     */
    public function validateToken(?string $submittedToken): bool
    {
        if (empty($submittedToken)) {
            return false;
        }

        $storedToken = $this->getSession()->get(self::TOKEN_NAME);

        if (empty($storedToken)) {
            return false;
        }

        // Use hash_equals to prevent timing attacks
        $isValid = hash_equals($storedToken, $submittedToken);

        // Regenerate token after validation (one-time use)
        if ($isValid) {
            $this->getSession()->remove(self::TOKEN_NAME);
        }

        return $isValid;
    }

    /**
     * Recupere la session courante
     *
     * @return \Symfony\Component\HttpFoundation\Session\SessionInterface
     * @throws \RuntimeException Si aucune session n'est disponible
     */
    private function getSession(): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request || !$request->hasSession()) {
            throw new \RuntimeException('No session available');
        }

        return $request->getSession();
    }
}
