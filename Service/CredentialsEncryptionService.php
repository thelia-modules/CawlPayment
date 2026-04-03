<?php

declare(strict_types=1);

namespace CawlPayment\Service;

use Thelia\Log\Tlog;
use Thelia\Model\ConfigQuery;

/**
 * Service de chiffrement AES-256-GCM pour les credentials sensibles
 * 
 * Ce service assure le chiffrement transparent des API keys, secrets et webhooks
 * stockes en base de donnees. Il supporte la migration progressive des valeurs
 * legacy (non chiffrees).
 */
class CredentialsEncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_ENV_VAR = 'CAWL_ENCRYPTION_KEY';
    private const CONFIG_KEY_NAME = 'cawl_encryption_key';
    private const TAG_LENGTH = 16;

    /**
     * Liste des cles de configuration sensibles qui doivent etre chiffrees
     */
    private const SENSITIVE_KEYS = [
        'api_key_test',
        'api_key_prod',
        'api_secret_test',
        'api_secret_prod',
        'webhook_key_test',
        'webhook_key_prod',
        'webhook_secret_test',
        'webhook_secret_prod',
    ];

    private ?string $encryptionKey = null;

    public function __construct()
    {
        $this->initializeKey();
    }

    /**
     * Verifie si une cle de configuration est sensible et doit etre chiffree
     */
    public function isSensitiveKey(string $key): bool
    {
        return in_array($key, self::SENSITIVE_KEYS, true);
    }

    /**
     * Chiffre une valeur en clair avec AES-256-GCM
     * 
     * @param string $plaintext La valeur a chiffrer
     * @return string La valeur chiffree encodee en base64
     * @throws \RuntimeException Si le chiffrement echoue
     */
    public function encrypt(string $plaintext): string
    {
        if (empty($plaintext)) {
            return '';
        }

        $key = $this->getKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        if ($ivLength === false) {
            throw new \RuntimeException('Failed to get IV length for cipher');
        }

        $iv = random_bytes($ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Format: base64(iv + tag + ciphertext)
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Dechiffre une valeur chiffree
     * 
     * Cette methode supporte les valeurs legacy (non chiffrees) pour permettre
     * une migration progressive.
     * 
     * @param string $encrypted La valeur chiffree ou en clair (legacy)
     * @return string La valeur en clair
     * @throws \RuntimeException Si le dechiffrement echoue (cle invalide ou donnees corrompues)
     */
    public function decrypt(string $encrypted): string
    {
        if (empty($encrypted)) {
            return '';
        }

        // Support des valeurs legacy (non chiffrees)
        if (!$this->isEncrypted($encrypted)) {
            return $encrypted;
        }

        $key = $this->getKey();
        $data = base64_decode($encrypted, true);

        if ($data === false) {
            throw new \RuntimeException('Decryption failed - invalid base64 encoding');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        if ($ivLength === false) {
            throw new \RuntimeException('Failed to get IV length for cipher');
        }

        $iv = substr($data, 0, $ivLength);
        $tag = substr($data, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($data, $ivLength + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed - invalid key or corrupted data');
        }

        return $plaintext;
    }

    /**
     * Detecte si une valeur est chiffree ou en clair (legacy)
     * 
     * Une valeur chiffree:
     * - Est encodee en base64 valide
     * - A une longueur minimale (IV + tag + au moins 1 octet de donnees)
     * - Ne ressemble pas a une cle API typique (pas de prefixes courants)
     */
    public function isEncrypted(string $value): bool
    {
        // Les API keys CAWL/Worldline typiques ont des formats specifiques
        // Eviter de considerer une vraie API key comme du base64 chiffre
        if ($this->looksLikePlainApiKey($value)) {
            return false;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        if ($ivLength === false) {
            return false;
        }

        $minLength = $ivLength + self::TAG_LENGTH + 1; // IV + tag + au moins 1 octet

        return strlen($decoded) >= $minLength;
    }

    /**
     * Verifie si une valeur ressemble a une API key en clair (non chiffree)
     */
    private function looksLikePlainApiKey(string $value): bool
    {
        // Les API keys CAWL/Worldline ont souvent ces caracteristiques:
        // - Commencent par des prefixes comme "sk_", "pk_", ou sont alphanumeriques simples
        // - Ont une longueur typique entre 20 et 100 caracteres
        // - Contiennent principalement des caracteres alphanumeriques, tirets, underscores

        if (strlen($value) < 10 || strlen($value) > 200) {
            return false;
        }

        // Si ca contient principalement des caracteres non-base64 (autres que a-zA-Z0-9+/=)
        // ou des caracteres de controle, ce n'est pas une API key normale
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return false;
        }

        // Prefixes courants d'API keys
        $apiKeyPrefixes = ['sk_', 'pk_', 'api_', 'key_', 'secret_', 'wh_'];

        foreach ($apiKeyPrefixes as $prefix) {
            if (str_starts_with(strtolower($value), $prefix)) {
                return true;
            }
        }

        // Si c'est une chaine alphanumerique simple avec tirets/underscores,
        // c'est une API key en clair (pas du base64 chiffre).
        // Les valeurs chiffrees par notre service contiennent des caracteres
        // base64 non-hex (+, /, =) car elles encodent des donnees binaires aleatoires.
        if (preg_match('/^[a-zA-Z0-9_\-]+$/', $value)) {
            return true;
        }

        // Les chaines purement hexadecimales sont des API keys, pas du chiffre
        if (preg_match('/^[0-9A-Fa-f]+$/', $value)) {
            return true;
        }

        return false;
    }

    /**
     * Initialise la cle de chiffrement depuis l'environnement ou la configuration
     */
    private function initializeKey(): void
    {
        // Priorite 1: Variable d'environnement (recommande pour la production)
        $key = getenv(self::KEY_ENV_VAR);

        if (!empty($key) && $key !== false) {
            $this->encryptionKey = $key;
            return;
        }

        // Priorite 2: Cle stockee en configuration Thelia
        try {
            $existingKey = ConfigQuery::read(self::CONFIG_KEY_NAME, '');

            if (!empty($existingKey)) {
                $this->encryptionKey = $existingKey;
                return;
            }
        } catch (\Exception $e) {
            // La base de donnees n'est peut-etre pas disponible
            Tlog::getInstance()->warning(
                '[CawlPayment] Could not read encryption key from database: ' . $e->getMessage()
            );
        }

        // Priorite 3: Generer une nouvelle cle (developpement uniquement)
        $newKey = base64_encode(random_bytes(32));

        try {
            ConfigQuery::write(self::CONFIG_KEY_NAME, $newKey);

            Tlog::getInstance()->warning(
                '[CawlPayment] Generated new encryption key. ' .
                'For production, set CAWL_ENCRYPTION_KEY environment variable.'
            );

            $this->encryptionKey = $newKey;
        } catch (\Exception $e) {
            // En cas d'echec, utiliser une cle temporaire (non persistee)
            Tlog::getInstance()->error(
                '[CawlPayment] Failed to persist encryption key: ' . $e->getMessage()
            );
            $this->encryptionKey = $newKey;
        }
    }

    /**
     * Retourne la cle de chiffrement sous forme binaire (32 octets pour AES-256)
     */
    private function getKey(): string
    {
        if (empty($this->encryptionKey)) {
            throw new \RuntimeException(
                'Encryption key not configured. Set CAWL_ENCRYPTION_KEY environment variable.'
            );
        }

        // Decoder si c'est une cle base64
        $key = base64_decode($this->encryptionKey, true);

        if ($key === false) {
            // La cle n'est pas en base64, utiliser comme mot de passe
            $key = $this->encryptionKey;
        }

        // S'assurer que la cle fait 32 octets pour AES-256
        if (strlen($key) !== 32) {
            // Deriver une cle de 32 octets avec SHA-256
            $key = hash('sha256', $this->encryptionKey, true);
        }

        return $key;
    }

    /**
     * Retourne la liste des cles sensibles
     */
    public function getSensitiveKeys(): array
    {
        return self::SENSITIVE_KEYS;
    }
}
