# Changelog - CawlPayment

Toutes les modifications notables de ce module sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Versionnement Sémantique](https://semver.org/lang/fr/).

---

## [1.0.0] - 2024-12-15

### Ajouté

#### Fonctionnalités principales
- **Intégration Worldline/CAWL Solutions** via le SDK PHP officiel
- **Hosted Checkout** : redirection vers la page de paiement sécurisée Worldline
- **Support multi-environnement** : basculement Test/Production
- **Dashboard de test API** dans l'administration

#### Méthodes de paiement (30+)
- **Cartes bancaires** : Visa, Mastercard, CB, American Express, Maestro, JCB, Diners Club, Discover
- **Portefeuilles numériques** : PayPal, Apple Pay, Google Pay, WeChat Pay, Alipay
- **Virements bancaires** : iDEAL, Bancontact, Giropay, EPS, Przelewy24, Sofort, Multibanco, TWINT
- **Paiement fractionné** : Klarna (Pay Now, Pay Later, Slice It), Oney 3x/4x
- **Titres restaurant** : Edenred, Sodexo, Up Déjeuner

#### Webhooks
- Réception des notifications de paiement en temps réel
- Validation des signatures HMAC-SHA256
- Mise à jour automatique du statut des commandes

#### Multi-langue
- Français (fr_FR)
- Anglais (en_US)
- Espagnol (es_ES)
- Italien (it_IT)
- Allemand (de_DE)

#### Intégrations
- Compatible avec le module OpenApi de Thelia
- Affichage des logos des méthodes de paiement depuis l'API
- Support des options de paiement via `PaymentModuleOptionEvent`

#### Administration
- Interface de configuration complète avec onglets
- Test de connexion API intégré
- Chargement dynamique des méthodes de paiement
- Configuration des limites de montant
- Journalisation configurable

#### Sécurité
- Validation HMAC-SHA256 des webhooks
- Rejet des webhooks sans secret en production
- Messages d'erreur génériques (pas d'exposition des détails internes)
- Journalisation sécurisée via Thelia Tlog
- Injection de dépendances Symfony

#### Base de données
- Table `cawl_transaction` pour le suivi des transactions
- Enregistrement des requêtes/réponses API
- Historique des statuts de paiement

#### Documentation
- README.md complet avec guide de configuration
- INSTALL.md avec procédure d'installation détaillée
- CHANGELOG.md pour le suivi des versions

### Technique

#### Architecture
- Compatible Thelia 2.6 / Symfony 6.4
- Utilisation de Propel ORM pour la persistance
- Services déclarés dans le conteneur Symfony
- EventSubscriber pour l'intégration OpenAPI

#### Fichiers principaux
```
CawlPayment.php              - Classe principale du module
Service/CawlApiService.php   - Service d'interaction avec l'API CAWL
Controller/Front/            - Contrôleurs paiement et webhook
Controller/Admin/            - Contrôleurs administration
EventListeners/              - Écouteurs pour OpenAPI
```

---

## [1.0.1] - 2026-06-24

### Corrigé — Compatibilité Thelia 2.6

#### `CawlPayment.php` — configureServices()
- Suppression de la redéclaration de `FrontHook` dans `configureServices()` : elle écrasait silencieusement les tags `hook.event_listener` déclarés dans `config.xml`, empêchant le hook front de se déclencher.
- Suppression de la redéclaration conditionnelle de `PaymentOptionsListener` : désormais géré exclusivement dans `config.xml`.
- Suppression de la déclaration publique de `CsrfTokenService` (inutilisée).

#### `Config/config.xml` — wiring des services
- Ajout de l'argument `<argument type="service" id="CawlPayment\Service\CawlApiService" />` sur la déclaration `FrontHook` (correction critique — le hook ne se déclenchait jamais).
- Déclaration de `PaymentOptionsListener` avec `on-invalid="null"` sur l'argument `open_api.model.factory` : le conteneur compile désormais correctement que le module OpenApi soit actif ou non.

#### `EventListeners/PaymentOptionsListener.php`
- Paramètre `$modelFactory` rendu nullable (`?ModelFactory`).
- Ajout d'un guard `if ($this->modelFactory === null) { return; }` en début de `onPaymentGetOptions()`.

#### `Hook/AdminHook.php`
- Suppression de `new CawlPayment()` pour récupérer l'URL webhook : remplacé par `ConfigQuery::read('url_site')` (instanciation directe du module hors conteneur est non-standard).

#### Suppression de `TestController`
- `Controller/Admin/TestController.php` supprimé : HTML inline dans le PHP, pas d'héritage `BaseAdminController`, logique dupliquée de `ConfigurationController`.
- Les 8 routes correspondantes retirées de `Config/routing.xml`.

#### Suppression des attributs `#[Route]` PHP
- Retirés de `ConfigurationController`, `PaymentController`, `WebhookController`, `AssetController` : `routing.xml` est la seule source de vérité pour les routes en T2.6.

### Modifié — Interface back-office

#### `templates/backOffice/default/module-configuration.html` — réécriture complète
- Suppression du bloc `<style>` de 315 lignes de CSS custom.
- Suppression de la barre violette avec dégradé (`.quick-actions`).
- Suppression du lien "Open Test Dashboard" (route supprimée).
- Remplacement des sections `.cawl-config-section` par `panel panel-default` / `panel-heading` / `panel-body`.
- Remplacement du toggle d'environnement custom (`.env-switch`) par `btn-group data-toggle="buttons"`.
- Remplacement des onglets credentials custom (JS/CSS maison) par `nav-tabs data-toggle="tab"` Bootstrap natif.
- Remplacement de `.credentials-grid` par `.row .col-md-6`.
- Bouton Save normalisé en `btn btn-primary` (sans taille forcée ni style inline).
- Bouton "Test API Connection" intégré directement dans la barre de titre (`pull-right`).
- Nettoyage JS : suppression du gestionnaire custom de tabs credentials ; conservation du test de connexion AJAX, `loadPaymentProducts` et `copyWebhookUrl`.

---

## [1.1.0] - 2026-06-24

### Ajouté

#### Outillage de test sandbox

- **Interface admin de test** (`feat: add test transaction UI and log viewer in admin`) : nouveau panneau dans l'onglet *Test Credentials* permettant de créer une transaction sandbox directement depuis l'administration, sans passer par le terminal.
  - Champs montant (en €, converti en centimes côté serveur) et devise
  - Lien cliquable vers l'URL de checkout Worldline après création
  - Affichage du `hostedCheckoutId`
  - Vérifications de configuration (environnement = test, pspid, api_key_test, api_secret_test) avant l'appel API

- **Viewer de logs** : panneau *Module logs* dans le même onglet, affichant les 50 dernières lignes `[CawlPayment]` du fichier `var/log/log-thelia.txt` avec bouton de rafraîchissement.
  - Lignes `[test-return]` colorées en vert, erreurs en rouge
  - Chargement automatique à l'ouverture de l'onglet

- **Commande console `cawlpayment:test-transaction`** (`feat: add cawlpayment:test-transaction console command`) : crée un hosted checkout de test sans commande Propel associée.
  - Options `--amount` (centimes, défaut 1000) et `--currency` (défaut EUR)
  - 7 vérifications pré-flight avec messages actionnables (env, module mode, pspid, api_key, api_secret, méthodes activées, connexion API live)

- **Champ `test_base_url`** (`feat: add test_base_url config for ngrok/tunnel local testing`) : URL de base configurable dans l'admin pour le callback de retour en développement local (ngrok, tunnel).
  - Guide ngrok collapsible dans le panneau de configuration test
  - Panneau d'aide avec les 5 étapes d'installation ngrok

- **Route `GET /cawlpayment/test-return`** (`fix: add test-return route to close hosted checkout session properly`) : endpoint de retour pour les checkouts de test, retourne le statut JSON du paiement et log le résultat dans `var/log/log-thelia.txt`.

- **Routes admin** : `POST /admin/module/CawlPayment/test-transaction` et `GET /admin/module/CawlPayment/logs`.

#### Tests unitaires

- **`1c29db0`** : tests unitaires pour `CawlApiService`, `SecureConfigService`, `PaymentOptionsListener`.
- **`7a6e0cd`** : tests unitaires pour le flux webhook et les transactions de paiement.

### Corrigé

- **`showResultPage(false)`** : Worldline n'affiche plus sa propre page de résultat — la redirection vers `returnUrl` est déclenchée immédiatement après le paiement.
- **`redirectUrl` prioritaire** (`fix: use redirectUrl from API response for checkout URL`) : l'URL de checkout utilise `redirectUrl` de la réponse API si disponible, avec fallback sur `checkoutUrl`.
- **Webhook Key non chiffrée** : `webhook_key_test` et `webhook_key_prod` retirés de `SENSITIVE_KEYS` dans `CredentialsEncryptionService` — ce sont des identifiants, pas des secrets. Le chiffrement était source de double-encodage lors de la sauvegarde.
- **Niveau de log `error()`** : les appels Tlog dans `testReturnAction` utilisent `error()` (le niveau par défaut de Tlog est ERROR ; `info()` et `warning()` étaient silencieusement ignorés).

---

## [Unreleased]

### Bug connu

- **Méthodes de paiement non filtrées en test** : `createTestHostedCheckout()` n'applique pas `PaymentProductFilters`, donc le hosted checkout affiche toutes les méthodes disponibles sur le compte marchand, quelle que soit la sélection dans la configuration du module.

### Prévu
- Appliquer le filtre `enabled_methods` dans `createTestHostedCheckout()` pour cohérence avec la production
- Support des remboursements partiels depuis l'admin Thelia
- Capture différée des paiements
- Rapports de transactions dans l'administration
- Support de PayByLink (liens de paiement)
- Intégration du widget de paiement embarqué

---

## Notes de mise à jour

### De 0.x vers 1.0.0

Cette version est la première release stable. Si vous utilisiez une version de développement :

1. Sauvegardez votre configuration actuelle
2. Désactivez l'ancien module
3. Supprimez les anciens fichiers
4. Installez la version 1.0.0
5. Réactivez et reconfigurez le module

### Migration de base de données

La table `cawl_transaction` est créée automatiquement lors de l'activation. Aucune migration manuelle n'est nécessaire.

---

## Versionnement

Ce module suit le versionnement sémantique :

- **MAJOR** (X.0.0) : Changements incompatibles avec les versions précédentes
- **MINOR** (0.X.0) : Nouvelles fonctionnalités rétrocompatibles
- **PATCH** (0.0.X) : Corrections de bugs rétrocompatibles

---

## Liens

- [Documentation CAWL](https://docs.direct.worldline-solutions.com/)
- [SDK PHP Worldline](https://github.com/Worldline-Global-Collect/connect-sdk-php)
- [Documentation Thelia](https://doc.thelia.net/)
