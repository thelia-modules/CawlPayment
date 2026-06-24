# CawlPayment - Module de Paiement CAWL Solutions pour Thelia 2.6

Module de paiement intégrant la passerelle **CAWL Solutions / Worldline** pour Thelia 2.6. Supporte plus de 30 méthodes de paiement incluant les cartes bancaires, portefeuilles numériques, virements et paiement fractionné.

## Table des matières

- [Fonctionnalités](#fonctionnalités)
- [Prérequis](#prérequis)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Identifiants API](#1-identifiants-api)
  - [Méthodes de paiement](#2-méthodes-de-paiement)
  - [Options](#3-options)
  - [Webhook](#4-configuration-webhook)
- [Méthodes de paiement supportées](#méthodes-de-paiement-supportées)
- [Test du module](#test-du-module)
- [Intégration Frontend](#intégration-frontend)
- [API OpenAPI](#api-openapi)
- [Dépannage](#dépannage)
- [Sécurité](#sécurité)
- [Changelog](#changelog)
- [Support](#support)

---

## Fonctionnalités

- **30+ méthodes de paiement** : Cartes (Visa, Mastercard, CB, Amex), PayPal, Apple Pay, Google Pay, Klarna, Bancontact, iDEAL, etc.
- **Environnement Test/Production** : Basculement facile entre les modes
- **Hosted Checkout** : Page de paiement sécurisée hébergée par Worldline
- **Webhooks** : Notifications de paiement en temps réel avec validation de signature HMAC-SHA256
- **Multi-langue** : FR, EN, ES, IT, DE
- **Compatible OpenAPI** : Intégration native avec le module OpenApi de Thelia
- **Logs détaillés** : Journalisation configurable pour le débogage
- **Dashboard de test** : Interface d'administration pour tester l'API

---

## Prérequis

- **Thelia** : Version 2.6.x
- **PHP** : 8.2 ou supérieur
- **Extensions PHP** : curl, json, openssl
- **SDK Worldline** : `online-payments/sdk-php: ^5.0` (installé via Composer)
- **Compte CAWL Solutions** : Identifiants API (PSPID, API Key, API Secret)

---

## Installation

### Via Composer (recommandé)

```bash
composer require cawl/thelia-payment
composer require online-payments/sdk-php:^5.0
```

### Installation manuelle

1. **Télécharger** le module et le placer dans `local/modules/CawlPayment/`

2. **Installer le SDK Worldline** :
```bash
composer require online-payments/sdk-php:^5.0
```

3. **Activer le module** via la CLI Thelia :
```bash
php Thelia module:activate CawlPayment
```

4. **Vider le cache** :
```bash
rm -rf var/cache/*
```

### Structure du module

```
local/modules/CawlPayment/
├── Config/
│   ├── config.xml          # Services et configuration
│   ├── module.xml          # Métadonnées du module
│   ├── routing.xml         # Routes
│   ├── schema.xml          # Schéma BDD Propel
│   └── TheliaMain.sql      # Script SQL d'installation
├── Controller/
│   ├── Admin/              # Contrôleurs backoffice
│   └── Front/              # Contrôleurs frontend
├── EventListeners/         # Écouteurs d'événements
├── I18n/                   # Traductions (fr_FR, en_US, es_ES, it_IT, de_DE)
├── Model/                  # Modèles Propel
├── Service/                # Services (CawlApiService)
├── templates/
│   └── backOffice/         # Templates administration
├── CawlPayment.php         # Classe principale du module
└── README.md               # Cette documentation
```

---

## Configuration

Accédez à la configuration via : **Administration > Modules > CawlPayment > Configurer**

### 1. Identifiants API

#### Environnement

Sélectionnez l'environnement actif :
- **Test** : Pour les développements et tests (sandbox Worldline)
- **Production** : Pour les transactions réelles

#### PSPID (Merchant ID)

Votre identifiant marchand CAWL Solutions. Cet identifiant est fourni lors de la création de votre compte.

#### Identifiants de Test

| Champ | Description |
|-------|-------------|
| Test API Key | Clé API pour l'environnement de test |
| Test API Secret | Secret API pour l'environnement de test |
| Test Webhook Key | Clé webhook pour l'environnement de test |
| Test Webhook Secret | Secret webhook pour valider les notifications |

#### Identifiants de Production

| Champ | Description |
|-------|-------------|
| Production API Key | Clé API pour l'environnement de production |
| Production API Secret | Secret API pour l'environnement de production |
| Production Webhook Key | Clé webhook pour l'environnement de production |
| Production Webhook Secret | Secret webhook pour valider les notifications |

> **Important** : Ne jamais utiliser les identifiants de test en production et vice-versa.

#### Tester la connexion

Cliquez sur le bouton **"Tester la connexion"** pour vérifier que vos identifiants sont corrects. Un message de succès ou d'erreur s'affichera.

### 2. Méthodes de paiement

Sélectionnez les méthodes de paiement à proposer à vos clients. Les méthodes sont chargées dynamiquement depuis l'API CAWL en fonction de votre contrat.

#### Catégories disponibles

| Catégorie | Méthodes |
|-----------|----------|
| **Cartes bancaires** | Visa, Mastercard, CB, American Express, Maestro, JCB, Diners Club |
| **Portefeuilles** | PayPal, Apple Pay, Google Pay, WeChat Pay, Alipay |
| **Virements bancaires** | iDEAL, Bancontact, Giropay, EPS, Przelewy24, Sofort, SEPA |
| **Paiement fractionné** | Klarna (Pay Now, Pay Later, Slice It), Oney 3x/4x |
| **Titres restaurant** | Edenred, Sodexo, Up Déjeuner |

### 3. Options

#### Journalisation

- **Activer les logs** : Active l'enregistrement des requêtes/réponses API dans `var/log/cawlpayment.log`

> **Attention** : Désactivez les logs en production pour des raisons de performance et de confidentialité.

#### Limites de montant

| Option | Description |
|--------|-------------|
| Montant minimum | Montant minimum pour utiliser ce mode de paiement (0 = pas de minimum) |
| Montant maximum | Montant maximum autorisé (0 = pas de maximum) |

### 4. Configuration Webhook

Les webhooks permettent de recevoir les notifications de paiement en temps réel (confirmation, échec, remboursement, etc.).

#### URL du Webhook

```
https://votre-site.com/cawlpayment/webhook
```

#### Configuration dans le back-office CAWL

1. Connectez-vous au [Portail Marchand CAWL](https://merchant.preprod.direct.worldline-solutions.com) (test) ou [Production](https://merchant.direct.worldline-solutions.com)
2. Allez dans **Configuration > Webhooks**
3. Ajoutez l'URL du webhook de votre site
4. Copiez la **Webhook Key** et le **Webhook Secret** générés
5. Collez-les dans la configuration du module Thelia

#### Événements webhook supportés

- `payment.created` - Paiement initié
- `payment.pending_capture` - En attente de capture
- `payment.captured` - Paiement capturé
- `payment.cancelled` - Paiement annulé
- `payment.rejected` - Paiement rejeté
- `payment.refunded` - Remboursement effectué

#### Sécurité Webhook - Whitelist IP

Pour renforcer la sécurité des webhooks, vous pouvez activer une whitelist d'adresses IP autorisées. Seules les requêtes provenant des IP listées seront acceptées.

| Option | Description |
|--------|-------------|
| **Activer la whitelist** | Active/désactive la vérification des IP sources |
| **Liste des IP autorisées** | Liste des adresses IP séparées par des virgules |

**IP Worldline à autoriser :**

```
# Environnement de test (preprod)
91.208.214.0/24
185.8.52.0/22

# Environnement de production
91.208.214.0/24
185.8.52.0/22
```

> **Note** : La whitelist IP est une couche de sécurité supplémentaire. La validation de signature HMAC-SHA256 reste la méthode principale de vérification.

---

## Méthodes de paiement supportées

### Cartes bancaires

| Code | Nom | ID Produit |
|------|-----|------------|
| `visa` | Visa | 1 |
| `mastercard` | Mastercard | 3 |
| `cb` | Carte Bancaire | 130 |
| `amex` | American Express | 2 |
| `maestro` | Maestro | 117 |
| `jcb` | JCB | 125 |
| `diners` | Diners Club | 132 |

### Portefeuilles numériques

| Code | Nom | ID Produit |
|------|-----|------------|
| `paypal` | PayPal | 840 |
| `applepay` | Apple Pay | 302 |
| `googlepay` | Google Pay | 320 |
| `wechatpay` | WeChat Pay | 863 |
| `alipay` | Alipay | 861 |

### Virements bancaires

| Code | Nom | ID Produit | Pays |
|------|-----|------------|------|
| `ideal` | iDEAL | 809 | Pays-Bas |
| `bancontact` | Bancontact | 3012 | Belgique |
| `giropay` | Giropay | 5408 | Allemagne |
| `eps` | EPS | 5700 | Autriche |
| `przelewy24` | Przelewy24 | 3124 | Pologne |
| `multibanco` | Multibanco | 5500 | Portugal |
| `twint` | TWINT | 5600 | Suisse |

### Paiement fractionné (BNPL)

| Code | Nom | ID Produit |
|------|-----|------------|
| `klarna_paynow` | Klarna Pay Now | 3301 |
| `klarna_paylater` | Klarna Pay Later | 3302 |
| `klarna_sliceit` | Klarna Slice It | 3303 |
| `oney3x` | Oney 3x | 5110 |
| `oney4x` | Oney 4x | 5111 |

---

## Test du module

### Cartes de test

Utilisez ces numéros de carte dans l'environnement de test :

#### Paiement réussi (Frictionless 3DS)

| Carte | Numéro | Date | CVV |
|-------|--------|------|-----|
| Visa | `4012 0000 3333 0026` | Toute date future | 123 |
| Mastercard | `5399 9999 9999 9999` | Toute date future | 123 |

#### Paiement avec Challenge 3DS

| Carte | Numéro | Date | CVV |
|-------|--------|------|-----|
| Visa | `4874 9700 0000 0014` | Toute date future | 123 |

#### Paiement refusé

| Carte | Numéro | Date | CVV | Raison |
|-------|--------|------|-----|--------|
| Visa | `4000 0200 0000 0000` | Toute date future | 123 | Fonds insuffisants |

### Test de connexion API

Depuis l'interface de configuration du module (**Administration > Modules > CawlPayment > Configurer**), le bouton **"Test API Connection"** (en haut à droite) permet de vérifier que les identifiants sont corrects et que l'API Worldline est accessible.

---

## Intégration Frontend

### Flux de paiement standard

1. Le client sélectionne CawlPayment comme mode de paiement
2. Le client choisit sa méthode de paiement (carte, PayPal, etc.)
3. Redirection vers la page Hosted Checkout de Worldline
4. Le client effectue le paiement
5. Redirection vers la page de confirmation Thelia
6. Le webhook confirme le statut final du paiement

### URLs de callback

| Route | URL | Description |
|-------|-----|-------------|
| Success | `/cawlpayment/success` | Retour après paiement réussi |
| Failure | `/cawlpayment/failure` | Retour après échec |
| Cancel | `/cawlpayment/cancel` | Retour après annulation |
| Webhook | `/cawlpayment/webhook` | Réception des notifications |

---

## API OpenAPI

Le module s'intègre avec le module OpenApi de Thelia pour exposer les options de paiement.

### Endpoint

```
GET /open_api/payment/module/{moduleId}/options
```

### Réponse

```json
{
  "paymentModuleOptionGroups": [
    {
      "code": "cawl_payment_methods",
      "title": "Choisissez votre moyen de paiement",
      "description": "Sélectionnez le moyen de paiement souhaité",
      "minimumSelectedOptions": 1,
      "maximumSelectedOptions": 1,
      "options": [
        {
          "code": "product_1",
          "title": "Visa",
          "description": "Visa",
          "image": "https://payment.preprod.direct.worldline-solutions.com/...",
          "valid": true
        }
      ]
    }
  ]
}
```

### Initier un paiement via API

```
POST /cawlpayment/pay/{orderId}/{methodCode}
```

---

## Dépannage

### Erreurs courantes

#### "No payment mode available"

**Cause** : Aucune méthode de paiement n'est activée ou les identifiants API sont incorrects.

**Solution** :
1. Vérifiez que le PSPID est configuré
2. Testez la connexion API
3. Activez au moins une méthode de paiement

#### "Invalid webhook signature"

**Cause** : Le secret webhook ne correspond pas ou n'est pas configuré.

**Solution** :
1. Vérifiez que le Webhook Secret est correctement copié depuis le portail CAWL
2. Assurez-vous d'utiliser le bon secret (test vs production)

#### "Controller not callable"

**Cause** : Le cache Symfony n'est pas à jour.

**Solution** :
```bash
rm -rf var/cache/*
```

#### Commande payée mais statut "Not paid"

**Cause** : Le webhook n'a pas été reçu ou traité.

**Solution** :
1. Vérifiez l'URL du webhook dans le portail CAWL
2. Vérifiez les logs dans `var/log/cawlpayment.log`
3. Assurez-vous que votre serveur accepte les requêtes POST externes

### Logs

Les logs sont enregistrés dans :
```
var/log/cawlpayment.log
```

Format des logs :
```
[2024-01-15 10:30:00] [CawlPayment] Creating hosted checkout for order #123
[2024-01-15 10:30:01] [CawlPayment] Hosted checkout created: abc123
[2024-01-15 10:32:00] [CawlPayment Webhook] Received webhook notification
[2024-01-15 10:32:00] [CawlPayment Webhook] Order #123 marked as PAID
```

---

## Sécurité

### Bonnes pratiques implémentées

- **Chiffrement AES-256-GCM** des credentials API en base de données (`SecureConfigService`)
- **Validation HMAC-SHA256** des webhooks
- **Pas d'exposition des erreurs internes** aux utilisateurs
- **Journalisation sécurisée** (pas de credentials dans les logs)
- **Injection de dépendances** pour les services
- **Protection CSRF** sur les formulaires admin
- **Vérification des permissions** admin

### Recommandations

1. **Désactivez les logs** en production
2. **Utilisez HTTPS** obligatoirement
3. **Configurez le Webhook Secret** en production
4. **Limitez les accès** au back-office
5. **Mettez à jour** régulièrement le module

---

## Changelog

Voir [CHANGELOG.md](CHANGELOG.md) pour l'historique complet des versions.

### Version 1.0.1 (2026-06-24)

- Correction compatibilité Thelia 2.6 (services, hooks, routing)
- Migration SDK `online-payments/sdk-php` v5 (`DefaultConnection`)
- Réécriture back-office : Bootstrap 3 natif, suppression CSS custom
- Chiffrement AES-256-GCM des credentials API en base de données

### Version 1.0.0 (2024-12)

- Version initiale
- Support de 30+ méthodes de paiement
- Intégration Hosted Checkout Worldline
- Support webhooks avec validation signature
- Multi-langue (FR, EN, ES, IT, DE)
- Intégration OpenAPI

---

## Support

### Documentation officielle

- [Documentation CAWL Solutions](https://docs.direct.worldline-solutions.com/)
- [SDK PHP Worldline v5](https://github.com/Online-Payments/sdk-php)
- [Portail Marchand Test](https://merchant.preprod.direct.worldline-solutions.com)
- [Portail Marchand Production](https://merchant.direct.worldline-solutions.com)

### Contact

Pour toute question ou problème :
- **Email** : support@cawl-solutions.com
- **Documentation Thelia** : https://doc.thelia.net/

---

## Licence

Ce module est distribué sous licence propriétaire CAWL Solutions.

© 2026 CAWL Solutions - Tous droits réservés.
