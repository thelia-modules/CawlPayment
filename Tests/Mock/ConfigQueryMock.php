<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Mock;

/**
 * Mock de Thelia\Model\ConfigQuery pour les tests unitaires
 *
 * Ce mock permet de simuler les operations de lecture/ecriture
 * de configuration sans dependance a la base de donnees.
 */
class ConfigQueryMock
{
    /**
     * Stockage en memoire des valeurs de configuration
     *
     * @var array<string, string>
     */
    private static array $values = [];

    /**
     * Lit une valeur de configuration
     *
     * @param string $key La cle de configuration
     * @param string|null $default La valeur par defaut
     * @return string|null La valeur ou null si non trouvee
     */
    public static function read(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? $default;
    }

    /**
     * Ecrit une valeur de configuration
     *
     * @param string $key La cle de configuration
     * @param string $value La valeur a ecrire
     * @param bool $secured Indique si la valeur est securisee (ignore dans le mock)
     * @param bool $hidden Indique si la valeur est cachee (ignore dans le mock)
     */
    public static function write(string $key, string $value, bool $secured = false, bool $hidden = false): void
    {
        self::$values[$key] = $value;
    }

    /**
     * Supprime une valeur de configuration
     *
     * @param string $key La cle de configuration
     */
    public static function delete(string $key): void
    {
        unset(self::$values[$key]);
    }

    /**
     * Reinitialise toutes les valeurs (utile entre les tests)
     */
    public static function reset(): void
    {
        self::$values = [];
    }

    /**
     * Definit plusieurs valeurs en une fois (pour setup de test)
     *
     * @param array<string, string> $values Les valeurs a definir
     */
    public static function setValues(array $values): void
    {
        self::$values = array_merge(self::$values, $values);
    }

    /**
     * Retourne toutes les valeurs actuelles (pour debugging)
     *
     * @return array<string, string>
     */
    public static function getAll(): array
    {
        return self::$values;
    }
}
