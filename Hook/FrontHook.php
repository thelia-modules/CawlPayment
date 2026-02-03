<?php

declare(strict_types=1);

namespace CawlPayment\Hook;

use CawlPayment\CawlPayment;
use CawlPayment\Service\CawlApiService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Core\Translation\Translator;
use Thelia\Model\ModuleQuery;

/**
 * Front office hooks for CAWL Payment module
 *
 * Note: Le constructeur est surchargé pour éviter un bug OPcache dans BaseHook.
 */
class FrontHook extends BaseHook
{
    /**
     * Override constructor to skip problematic module instantiation in BaseHook.
     */
    public function __construct(
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        if ($dispatcher instanceof EventDispatcherInterface) {
            $this->dispatcher = $dispatcher;
        }

        if ($parserResolver instanceof ParserResolver) {
            $this->parserResolver = $parserResolver;
        }

        // Skip module instantiation - it will be injected by Symfony DI

        $this->translator = Translator::getInstance();
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
        $module = new CawlPayment();
        $enabledMethods = $module->getEnabledPaymentMethods();

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
            $apiService = new CawlApiService();
            $result = $apiService->getPaymentProductsCached();

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
