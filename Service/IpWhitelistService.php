<?php

declare(strict_types=1);

namespace CawlPayment\Service;

use CawlPayment\CawlPayment;

/**
 * Service de gestion de la whitelist IP pour les webhooks
 *
 * Ce service vérifie si une adresse IP est autorisée à accéder au webhook.
 * Supporte les adresses IP individuelles et les plages CIDR (IPv4).
 */
class IpWhitelistService
{
    /**
     * Vérifie si l'adresse IP du client est autorisée
     *
     * @param string $clientIp Adresse IP du client
     * @return bool True si l'IP est autorisée, false sinon
     */
    public function isIpAllowed(string $clientIp): bool
    {
        // Vérifier si la whitelist est activée
        $whitelistEnabled = (bool) CawlPayment::getConfigValue('webhook_whitelist_enabled', '1');

        $module = new CawlPayment();

        // En mode test avec whitelist désactivée, autoriser toutes les IPs
        if (!$module->isProductionMode() && !$whitelistEnabled) {
            return true;
        }

        // Récupérer les IPs autorisées
        $allowedIps = $this->getAllowedIps();

        // Si aucune IP configurée et whitelist désactivée, autoriser toutes les IPs
        if (empty($allowedIps) && !$whitelistEnabled) {
            return true;
        }

        // Si whitelist activée mais vide, bloquer par sécurité
        if (empty($allowedIps) && $whitelistEnabled) {
            return false;
        }

        // Vérifier l'IP contre la whitelist
        return $this->checkIpAgainstWhitelist($clientIp, $allowedIps);
    }

    /**
     * Indique si la whitelist restreint réellement les appelants.
     *
     * Stricte = activée ET au moins une IP/CIDR configurée. Une whitelist
     * activée mais vide bloque tout (donc n'autorise personne), une whitelist
     * désactivée n'autorise rien de particulier : dans les deux cas elle ne
     * constitue pas une restriction exploitable comme garde-fou.
     */
    public function isStrict(): bool
    {
        $enabled = (bool) CawlPayment::getConfigValue('webhook_whitelist_enabled', '1');

        return $enabled && !empty($this->getAllowedIps());
    }

    /**
     * Récupère la liste des IPs autorisées depuis la configuration
     *
     * @return array<string> Liste des IPs ou plages CIDR
     */
    private function getAllowedIps(): array
    {
        $configuredIps = CawlPayment::getConfigValue('webhook_ip_whitelist', '');
        return array_filter(array_map('trim', explode(',', $configuredIps)));
    }

    /**
     * Vérifie si une IP correspond à la whitelist
     *
     * @param string $clientIp Adresse IP à vérifier
     * @param array<string> $allowedIps Liste des IPs/CIDR autorisées
     * @return bool True si l'IP correspond à une entrée de la whitelist
     */
    private function checkIpAgainstWhitelist(string $clientIp, array $allowedIps): bool
    {
        foreach ($allowedIps as $allowed) {
            if ($this->ipMatchesCidr($clientIp, $allowed)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vérifie si une IP correspond à une adresse ou une plage CIDR
     *
     * @param string $ip Adresse IP à vérifier
     * @param string $cidr Adresse IP ou notation CIDR (ex: 192.168.1.0/24)
     * @return bool True si l'IP correspond
     */
    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        // Nettoyer les entrées
        $ip = trim($ip);
        $cidr = trim($cidr);

        if (empty($ip) || empty($cidr)) {
            return false;
        }

        // Correspondance exacte (IP sans masque)
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        // Notation CIDR
        $parts = explode('/', $cidr);
        if (count($parts) !== 2) {
            return false;
        }

        [$subnet, $maskBits] = $parts;

        // Support IPv4 uniquement pour le moment
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $maskBitsInt = (int) $maskBits;

        // Validation du masque (0-32 pour IPv4)
        if ($maskBitsInt < 0 || $maskBitsInt > 32) {
            return false;
        }

        // Calculer le masque de sous-réseau
        $mask = $maskBitsInt === 0 ? 0 : (-1 << (32 - $maskBitsInt));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Valide le format d'une liste d'IPs/CIDR
     *
     * @param string $ipList Liste séparée par des virgules
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateIpList(string $ipList): array
    {
        $errors = [];
        $ips = array_filter(array_map('trim', explode(',', $ipList)));

        foreach ($ips as $entry) {
            if (!$this->isValidIpOrCidr($entry)) {
                $errors[] = sprintf('Format IP invalide: %s', $entry);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Vérifie si une entrée est une IP valide ou une notation CIDR valide
     *
     * @param string $entry IP ou CIDR à valider
     * @return bool True si le format est valide
     */
    private function isValidIpOrCidr(string $entry): bool
    {
        $entry = trim($entry);

        if (empty($entry)) {
            return false;
        }

        // IP simple
        if (strpos($entry, '/') === false) {
            return filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        // Notation CIDR
        $parts = explode('/', $entry);
        if (count($parts) !== 2) {
            return false;
        }

        [$ip, $mask] = $parts;

        // Valider l'IP
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        // Valider le masque (0-32)
        $maskInt = filter_var($mask, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 32]
        ]);

        return $maskInt !== false;
    }
}
