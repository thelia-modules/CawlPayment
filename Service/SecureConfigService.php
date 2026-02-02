<?php

declare(strict_types=1);

namespace CawlPayment\Service;

use CawlPayment\CawlPayment;
use Thelia\Log\Tlog;

/**
 * Service de configuration securise pour CawlPayment
 * 
 * Ce service encapsule l'acces aux valeurs de configuration du module
 * en assurant le chiffrement/dechiffrement transparent des credentials sensibles.
 */
class SecureConfigService
{
    public function __construct(
        private readonly CredentialsEncryptionService $encryptionService
    ) {
    }

    /**
     * Enregistre une valeur de configuration
     * 
     * Les valeurs sensibles (API keys, secrets) sont automatiquement chiffrees.
     * 
     * @param string $key Le nom de la cle de configuration
     * @param string $value La valeur a enregistrer
     */
    public function setConfigValue(string $key, string $value): void
    {
        // Chiffrer les valeurs sensibles
        if ($this->encryptionService->isSensitiveKey($key) && !empty($value)) {
            try {
                $value = $this->encryptionService->encrypt($value);
            } catch (\Exception $e) {
                Tlog::getInstance()->error(
                    '[CawlPayment] Failed to encrypt config value for key "' . $key . '": ' . $e->getMessage()
                );
                throw new \RuntimeException('Failed to secure configuration value');
            }
        }

        CawlPayment::setConfigValue($key, $value);
    }

    /**
     * Recupere une valeur de configuration
     * 
     * Les valeurs sensibles (API keys, secrets) sont automatiquement dechiffrees.
     * Supporte les valeurs legacy (non chiffrees) pour une migration progressive.
     * 
     * @param string $key Le nom de la cle de configuration
     * @param string $default La valeur par defaut si la cle n'existe pas
     * @return string La valeur en clair
     */
    public function getConfigValue(string $key, string $default = ''): string
    {
        $value = CawlPayment::getConfigValue($key, $default);

        // Dechiffrer les valeurs sensibles
        if ($this->encryptionService->isSensitiveKey($key) && !empty($value)) {
            try {
                $value = $this->encryptionService->decrypt($value);
            } catch (\Exception $e) {
                Tlog::getInstance()->error(
                    '[CawlPayment] Failed to decrypt config value for key "' . $key . '": ' . $e->getMessage()
                );

                // En cas d'echec de dechiffrement, retourner la valeur par defaut
                // plutot que d'exposer une valeur potentiellement corrompue
                return $default;
            }
        }

        return $value;
    }

    /**
     * Verifie si une cle de configuration est sensible
     */
    public function isSensitiveKey(string $key): bool
    {
        return $this->encryptionService->isSensitiveKey($key);
    }

    /**
     * Migre les credentials legacy (non chiffres) vers le format chiffre
     * 
     * Cette methode peut etre appelee manuellement ou lors de l'activation
     * du module pour chiffrer les credentials existants.
     * 
     * @return array Resultat de la migration avec les cles traitees
     */
    public function migrateCredentials(): array
    {
        $result = [
            'migrated' => [],
            'already_encrypted' => [],
            'empty' => [],
            'errors' => [],
        ];

        foreach ($this->encryptionService->getSensitiveKeys() as $key) {
            $rawValue = CawlPayment::getConfigValue($key, '');

            if (empty($rawValue)) {
                $result['empty'][] = $key;
                continue;
            }

            // Verifier si deja chiffre
            if ($this->encryptionService->isEncrypted($rawValue)) {
                $result['already_encrypted'][] = $key;
                continue;
            }

            // Migrer: chiffrer la valeur en clair
            try {
                $encryptedValue = $this->encryptionService->encrypt($rawValue);
                CawlPayment::setConfigValue($key, $encryptedValue);
                $result['migrated'][] = $key;

                Tlog::getInstance()->info(
                    '[CawlPayment] Migrated credential "' . $key . '" to encrypted format'
                );
            } catch (\Exception $e) {
                $result['errors'][$key] = $e->getMessage();

                Tlog::getInstance()->error(
                    '[CawlPayment] Failed to migrate credential "' . $key . '": ' . $e->getMessage()
                );
            }
        }

        return $result;
    }

    /**
     * Verifie l'etat de chiffrement de tous les credentials
     * 
     * @return array Status de chaque credential (encrypted, plaintext, empty)
     */
    public function getCredentialsStatus(): array
    {
        $status = [];

        foreach ($this->encryptionService->getSensitiveKeys() as $key) {
            $rawValue = CawlPayment::getConfigValue($key, '');

            if (empty($rawValue)) {
                $status[$key] = 'empty';
            } elseif ($this->encryptionService->isEncrypted($rawValue)) {
                $status[$key] = 'encrypted';
            } else {
                $status[$key] = 'plaintext';
            }
        }

        return $status;
    }
}
