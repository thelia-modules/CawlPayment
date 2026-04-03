<?php

declare(strict_types=1);

namespace CawlPayment\Hook;

use CawlPayment\Service\CawlApiService;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Model\ModuleQuery;

/**
 * Front office hooks for CAWL Payment module
 *
 * Uses default BaseHook constructor which loads the module via ModuleQuery.
 * Required because BaseHook::render() needs $this->module for template path resolution.
 */
class FrontHook extends BaseHook
{
    private CawlApiService $apiService;

    public function __construct(CawlApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * Display payment method options in the checkout
     * This hook is called for each payment module in order-invoice.payment-extra
     */
    public function onPaymentExtra(HookRenderEvent $event): void
    {
        // Get the module ID from the hook argument
        $moduleId = $event->getArgument('module');

        // Get CawlPayment module ID
        $cawlModule = ModuleQuery::create()
            ->filterByCode('CawlPayment')
            ->filterByActivate(1)
            ->findOne();

        if (!$cawlModule || $cawlModule->getId() != $moduleId) {
            return;
        }

        // Get enabled payment methods
        $enabledMethods = $this->apiService->getEnabledPaymentMethods();

        if (empty($enabledMethods)) {
            return;
        }

        // Try to enrich with logos from API
        $paymentMethods = $this->enrichWithApiLogos($enabledMethods);

        $event->add($this->render('order-invoice.payment-extra.html', [
            'enabled_methods' => $paymentMethods,
            'module_id' => $moduleId,
        ]));
    }

    /**
     * Enrich payment methods with logos from API
     */
    private function enrichWithApiLogos(array $enabledMethods): array
    {
        try {
            $result = $this->apiService->getPaymentProductsCached();

            if (!$result['success'] || empty($result['products'])) {
                // Return methods without logos
                return $this->ensureLogoKey($enabledMethods);
            }

            // Build a map of product ID to logo URL
            $logoMap = [];
            foreach ($result['products'] as $product) {
                $logoMap[$product['id']] = $product['displayHints']['logo'] ?? '';
            }

            // Enrich each method with logo
            foreach ($enabledMethods as $code => &$method) {
                // For new format (product_X), extract ID
                if (strpos($code, 'product_') === 0) {
                    $productId = (int) substr($code, 8);
                    $method['logo'] = $logoMap[$productId] ?? '';
                }
                // For legacy format, map to product ID
                elseif (isset($method['id'])) {
                    $method['logo'] = $logoMap[$method['id']] ?? '';
                }
                // Ensure logo key exists
                else {
                    $method['logo'] = $method['logo'] ?? '';
                }
            }

            return $enabledMethods;
        } catch (\Exception $e) {
            // Return methods without logos on error
            return $this->ensureLogoKey($enabledMethods);
        }
    }

    /**
     * Ensure all methods have a logo key (even if empty)
     */
    private function ensureLogoKey(array $methods): array
    {
        foreach ($methods as $code => &$method) {
            if (!isset($method['logo'])) {
                $method['logo'] = '';
            }
        }
        return $methods;
    }
}
