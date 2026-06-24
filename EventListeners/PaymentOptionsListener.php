<?php

declare(strict_types=1);

namespace CawlPayment\EventListeners;

use CawlPayment\CawlPayment;
use CawlPayment\Service\CawlApiService;
use OpenApi\Events\OpenApiEvents;

use OpenApi\Events\PaymentModuleOptionEvent;
use OpenApi\Model\Api\ModelFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Translation\Translator;

/**
 * Listener to provide payment method options for the OpenAPI
 */
class PaymentOptionsListener implements EventSubscriberInterface
{
    private ?ModelFactory $modelFactory;
    private CawlApiService $apiService;

    public function __construct(
        ?ModelFactory $modelFactory,
        CawlApiService $apiService
    ) {
        $this->modelFactory = $modelFactory;
        $this->apiService = $apiService;
    }

    /**
     * Translate a string using the module domain
     */
    private function trans(string $id): string
    {
        return Translator::getInstance()->trans($id, [], CawlPayment::DOMAIN_NAME);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OpenApiEvents::MODULE_PAYMENT_GET_OPTIONS => ['onPaymentGetOptions', 128],
        ];
    }

    public function onPaymentGetOptions(PaymentModuleOptionEvent $event): void
    {
        if ($this->modelFactory === null) {
            return;
        }

        $module = $event->getModule();

        // Only handle CawlPayment module
        if ($module->getCode() !== 'CawlPayment') {
            return;
        }

        $enabledMethods = $this->apiService->getEnabledPaymentMethods();

        if (empty($enabledMethods)) {
            return;
        }

        // Get logos from CAWL API
        $logoMap = $this->getLogoMap();

        // Create option group for payment methods using ModelFactory
        $optionGroup = $this->modelFactory->buildModel('PaymentModuleOptionGroup');
        $optionGroup->setCode('cawl_payment_methods');
        $optionGroup->setTitle($this->trans('Choose your payment method'));
        $optionGroup->setDescription($this->trans('Select your desired payment method'));
        $optionGroup->setMinimumSelectedOptions(1);
        $optionGroup->setMaximumSelectedOptions(1);
        $optionGroup->setOptions([]);

        foreach ($enabledMethods as $code => $method) {
            $option = $this->modelFactory->buildModel('PaymentModuleOption');
            $option->setCode($code);
            $option->setTitle($method['name']);
            $option->setDescription($method['name']);
            $option->setValid(true);

            // Get logo URL from API map
            $productId = $method['id'] ?? 0;
            $logoUrl = $logoMap[$productId] ?? '';
            $option->setImage($logoUrl);

            $optionGroup->appendPaymentModuleOption($option);
        }

        $event->appendPaymentModuleOptionGroups($optionGroup);
    }

    /**
     * Get logo URLs from CAWL API (cached)
     */
    private function getLogoMap(): array
    {
        $logoMap = [];

        try {
            $result = $this->apiService->getPaymentProductsCached();

            if ($result['success'] && !empty($result['products'])) {
                foreach ($result['products'] as $product) {
                    $logoMap[$product['id']] = $product['displayHints']['logo'] ?? '';
                }
            }
        } catch (\Exception $e) {
            // Return empty map on error
        }

        return $logoMap;
    }
}
