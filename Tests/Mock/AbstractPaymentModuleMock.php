<?php

declare(strict_types=1);

namespace CawlPayment\Tests\Mock;

/**
 * Mock de Thelia\Module\AbstractPaymentModule pour les tests unitaires
 *
 * Ce mock fournit une implementation minimale du module de paiement
 * permettant d'executer les tests sans Thelia complet.
 */
abstract class AbstractPaymentModuleMock
{
    /**
     * Stockage en memoire des valeurs de configuration du module
     *
     * @var array<string, string>
     */
    protected static array $configValues = [];

    /**
     * Mode production (false = test par defaut)
     */
    protected static bool $productionMode = false;

    /**
     * Retourne une valeur de configuration du module
     *
     * @param string $key La cle de configuration
     * @param string $default La valeur par defaut
     * @return string La valeur de configuration
     */
    public static function getConfigValue(string $key, string $default = ''): string
    {
        return static::$configValues[$key] ?? $default;
    }

    /**
     * Definit une valeur de configuration du module
     *
     * @param string $key La cle de configuration
     * @param string $value La valeur a stocker
     */
    public static function setConfigValue(string $key, string $value): void
    {
        static::$configValues[$key] = $value;
    }

    /**
     * Verifie si le module est en mode production
     *
     * @return bool True si mode production, false si mode test
     */
    public function isProductionMode(): bool
    {
        return static::$productionMode;
    }

    /**
     * Definit le mode du module (pour les tests)
     *
     * @param bool $production True pour mode production
     */
    public static function setProductionMode(bool $production): void
    {
        static::$productionMode = $production;
    }

    /**
     * Reinitialise toutes les valeurs de configuration (pour les tests)
     */
    public static function resetConfig(): void
    {
        static::$configValues = [];
        static::$productionMode = false;
    }

    /**
     * Definit plusieurs valeurs de configuration en une fois
     *
     * @param array<string, string> $values Les valeurs a definir
     */
    public static function setConfigValues(array $values): void
    {
        static::$configValues = array_merge(static::$configValues, $values);
    }
}
