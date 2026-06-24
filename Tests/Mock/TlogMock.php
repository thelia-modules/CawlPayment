<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Mock;

/**
 * Mock de Thelia\Log\Tlog pour les tests unitaires
 *
 * Ce mock capture les messages de log sans ecriture effective,
 * permettant de verifier les appels de log dans les tests.
 */
class TlogMock
{
    private static ?self $instance = null;

    /**
     * Messages de log captures par niveau
     *
     * @var array<string, array<string>>
     */
    private array $messages = [];

    /**
     * Retourne l'instance singleton
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Crée une nouvelle instance indépendante (API du vrai Tlog)
     */
    public static function getNewInstance(): self
    {
        return new self();
    }

    /**
     * No-op : le vrai Tlog configure les destinations
     */
    public function setDestinations(string $destinations): self
    {
        return $this;
    }

    /**
     * No-op : le vrai Tlog configure les paramètres d'une destination
     */
    public function setConfig(string $destination, string $level, string $path): self
    {
        return $this;
    }

    /**
     * Reinitialise l'instance (utile entre les tests)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Log un message de niveau debug
     */
    public function debug(string $message): void
    {
        $this->log('debug', $message);
    }

    /**
     * Log un message de niveau info
     */
    public function info(string $message): void
    {
        $this->log('info', $message);
    }

    /**
     * Log un message de niveau warning
     */
    public function warning(string $message): void
    {
        $this->log('warning', $message);
    }

    /**
     * Log un message de niveau error
     */
    public function error(string $message): void
    {
        $this->log('error', $message);
    }

    /**
     * Log un message de niveau critical
     */
    public function critical(string $message): void
    {
        $this->log('critical', $message);
    }

    /**
     * Enregistre un message de log
     */
    private function log(string $level, string $message): void
    {
        if (!isset($this->messages[$level])) {
            $this->messages[$level] = [];
        }
        $this->messages[$level][] = $message;
    }

    /**
     * Retourne tous les messages d'un niveau donne
     *
     * @return array<string>
     */
    public function getMessages(string $level): array
    {
        return $this->messages[$level] ?? [];
    }

    /**
     * Retourne tous les messages captures
     *
     * @return array<string, array<string>>
     */
    public function getAllMessages(): array
    {
        return $this->messages;
    }

    /**
     * Verifie si un message contenant une sous-chaine a ete log
     */
    public function hasMessageContaining(string $level, string $substring): bool
    {
        foreach ($this->getMessages($level) as $message) {
            if (str_contains($message, $substring)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reinitialise les messages (sans reinitialiser l'instance)
     */
    public function clearMessages(): void
    {
        $this->messages = [];
    }
}
