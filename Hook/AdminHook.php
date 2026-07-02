<?php

declare(strict_types=1);

namespace CawlPayment\Hook;

use CawlPayment\CawlPayment;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

/**
 * Admin hook for CAWL Payment module configuration
 *
 * Uses the default BaseHook constructor which loads the module via ModuleQuery.
 * This is required because BaseHook::render() needs $this->module to resolve
 * template paths.
 */
class AdminHook extends BaseHook
{
    /**
     * Clé de session pour le token CSRF
     */
    private const CSRF_TOKEN_KEY = 'cawlpayment_csrf_token';

    /**
     * Render module configuration content
     */
    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        $moduleCode = $event->getArgument('module_code');

        // Try alternate argument name
        if (empty($moduleCode)) {
            $moduleCode = $event->getArgument('modulecode');
        }

        if ($moduleCode !== 'CawlPayment') {
            return;
        }

        // Get current configuration values
        $config = [
            'pspid' => CawlPayment::getConfigValue('pspid', ''),
            'api_key_test' => CawlPayment::getConfigValue('api_key_test', ''),
            'api_secret_test' => CawlPayment::getConfigValue('api_secret_test', ''),
            'api_key_prod' => CawlPayment::getConfigValue('api_key_prod', ''),
            'api_secret_prod' => CawlPayment::getConfigValue('api_secret_prod', ''),
            'webhook_key_test' => CawlPayment::getConfigValue('webhook_key_test', ''),
            'webhook_secret_test' => CawlPayment::getConfigValue('webhook_secret_test', ''),
            'webhook_key_prod' => CawlPayment::getConfigValue('webhook_key_prod', ''),
            'webhook_secret_prod' => CawlPayment::getConfigValue('webhook_secret_prod', ''),
            'environment' => CawlPayment::getConfigValue('environment', CawlPayment::ENV_TEST),
            'capture_mode' => CawlPayment::getConfigValue('capture_mode', CawlPayment::CAPTURE_MODE_SALE),
            'enabled_methods' => CawlPayment::getConfigValue('enabled_methods', ''),
            'enable_logging' => CawlPayment::getConfigValue('enable_logging', '1'),
            'checkout_description' => CawlPayment::getConfigValue('checkout_description', ''),
            'min_amount' => CawlPayment::getConfigValue('min_amount', '0'),
            'max_amount' => CawlPayment::getConfigValue('max_amount', '0'),
            'webhook_ip_whitelist' => CawlPayment::getConfigValue('webhook_ip_whitelist', ''),
            'webhook_whitelist_enabled' => CawlPayment::getConfigValue('webhook_whitelist_enabled', '1'),
            'test_base_url' => CawlPayment::getConfigValue('test_base_url', ''),
        ];

        // Parse enabled methods into array
        $enabledMethodsList = array_filter(array_map('trim', explode(',', $config['enabled_methods'])));

        // Get all payment methods by category
        $methodsByCategory = CawlPayment::getPaymentMethodsByCategory();

        $baseUrl = \Thelia\Model\ConfigQuery::read('url_site', '');
        $webhookUrl = rtrim($baseUrl, '/') . '/cawlpayment/webhook';

        // Check if credentials are configured
        $hasTestCredentials = !empty($config['api_key_test']) && !empty($config['api_secret_test']);
        $hasProdCredentials = !empty($config['api_key_prod']) && !empty($config['api_secret_prod']);

        // Generate CSRF token and store directly in session
        $formToken = bin2hex(random_bytes(32));
        $this->getSession()->set(self::CSRF_TOKEN_KEY, $formToken);

        $event->add($this->render('module-configuration.html', [
            'config' => $config,
            'config_json' => json_encode($config),
            'enabled_methods_list' => $enabledMethodsList,
            'methods_by_category' => $methodsByCategory,
            'all_methods' => CawlPayment::PAYMENT_METHODS,
            'categories' => CawlPayment::CATEGORIES,
            'webhook_url' => $webhookUrl,
            'has_test_credentials' => $hasTestCredentials,
            'has_prod_credentials' => $hasProdCredentials,
            'is_production' => $config['environment'] === CawlPayment::ENV_PRODUCTION,
            'form_token' => $formToken,
            'webhook_key_test' => $config['webhook_key_test'],
            'webhook_key_prod' => $config['webhook_key_prod'],
        ]));
    }
}
