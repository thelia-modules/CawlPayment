<?php

declare(strict_types=1);

// Stubs pour les classes OpenApi (module optionnel, absent en environnement de test)
namespace OpenApi\Events {
    if (!class_exists(OpenApiEvents::class, false)) {
        class OpenApiEvents
        {
            public const MODULE_PAYMENT_GET_OPTIONS = 'module.payment.get.options';
        }
    }

    if (!class_exists(PaymentModuleOptionEvent::class, false)) {
        class PaymentModuleOptionEvent
        {
            private object $module;
            private array $appendedGroups = [];

            public function setModule(object $module): void
            {
                $this->module = $module;
            }

            public function getModule(): object
            {
                return $this->module;
            }

            public function appendPaymentModuleOptionGroups(object $group): void
            {
                $this->appendedGroups[] = $group;
            }

            public function getAppendedGroups(): array
            {
                return $this->appendedGroups;
            }
        }
    }
}

namespace OpenApi\Model\Api {
    if (!class_exists(ModelFactory::class, false)) {
        class ModelFactory
        {
            public function buildModel(string $type): object
            {
                return new class {
                    private array $data = [];

                    public function __call(string $name, array $args): static
                    {
                        if (str_starts_with($name, 'set')) {
                            $this->data[$name] = $args[0];
                        }
                        return $this;
                    }

                    public function getData(): array
                    {
                        return $this->data;
                    }
                };
            }
        }
    }
}

// Namespace du test
namespace CawlPayment\Tests\EventListeners;

use CawlPayment\CawlPayment;
use CawlPayment\EventListeners\PaymentOptionsListener;
use CawlPayment\Service\CawlApiService;
use CawlPayment\Tests\Mock\TlogMock;
use OpenApi\Events\PaymentModuleOptionEvent;
use OpenApi\Model\Api\ModelFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour PaymentOptionsListener
 *
 * Couvre :
 *  - getSubscribedEvents()    : mapping événement → méthode
 *  - onPaymentGetOptions()    : tous les cas de garde et le chemin nominal
 */
class PaymentOptionsListenerTest extends TestCase
{
    private CawlApiService $apiServiceMock;
    private ModelFactory $modelFactoryMock;

    protected function setUp(): void
    {
        CawlPayment::resetConfig();
        TlogMock::reset();

        $this->apiServiceMock = $this->createMock(CawlApiService::class);
        $this->modelFactoryMock = $this->createMock(ModelFactory::class);
    }

    protected function tearDown(): void
    {
        CawlPayment::resetConfig();
        TlogMock::reset();
    }

    private function makeEvent(string $moduleCode): PaymentModuleOptionEvent
    {
        $module = new class($moduleCode) {
            public function __construct(private string $code) {}
            public function getCode(): string { return $this->code; }
        };

        $event = new PaymentModuleOptionEvent();
        $event->setModule($module);
        return $event;
    }

    // =========================================================================
    // getSubscribedEvents()
    // =========================================================================

    public function testGetSubscribedEventsReturnsCorrectMapping(): void
    {
        $events = PaymentOptionsListener::getSubscribedEvents();

        $this->assertArrayHasKey(\OpenApi\Events\OpenApiEvents::MODULE_PAYMENT_GET_OPTIONS, $events);

        $handler = $events[\OpenApi\Events\OpenApiEvents::MODULE_PAYMENT_GET_OPTIONS];
        $this->assertSame('onPaymentGetOptions', $handler[0]);
        $this->assertSame(128, $handler[1]);
    }

    // =========================================================================
    // onPaymentGetOptions() — gardes
    // =========================================================================

    public function testOnPaymentGetOptionsDoesNothingWhenModelFactoryIsNull(): void
    {
        $listener = new PaymentOptionsListener(null, $this->apiServiceMock);
        $event = $this->makeEvent('CawlPayment');

        $this->apiServiceMock->expects($this->never())->method('getEnabledPaymentMethods');

        $listener->onPaymentGetOptions($event);

        $this->assertEmpty($event->getAppendedGroups());
    }

    public function testOnPaymentGetOptionsDoesNothingForOtherModule(): void
    {
        $listener = new PaymentOptionsListener($this->modelFactoryMock, $this->apiServiceMock);
        $event = $this->makeEvent('OtherPaymentModule');

        $this->apiServiceMock->expects($this->never())->method('getEnabledPaymentMethods');

        $listener->onPaymentGetOptions($event);

        $this->assertEmpty($event->getAppendedGroups());
    }

    public function testOnPaymentGetOptionsDoesNothingWhenNoEnabledMethods(): void
    {
        $listener = new PaymentOptionsListener($this->modelFactoryMock, $this->apiServiceMock);
        $event = $this->makeEvent('CawlPayment');

        $this->apiServiceMock->method('getEnabledPaymentMethods')->willReturn([]);
        $this->modelFactoryMock->expects($this->never())->method('buildModel');

        $listener->onPaymentGetOptions($event);

        $this->assertEmpty($event->getAppendedGroups());
    }

    // =========================================================================
    // onPaymentGetOptions() — chemin nominal
    // =========================================================================

    public function testOnPaymentGetOptionsAppendsOptionGroupForEnabledMethods(): void
    {
        $listener = new PaymentOptionsListener($this->modelFactoryMock, $this->apiServiceMock);
        $event = $this->makeEvent('CawlPayment');

        $enabledMethods = [
            'visa' => ['id' => 1, 'name' => 'Visa', 'category' => 'card', 'icon' => ''],
            'mastercard' => ['id' => 3, 'name' => 'Mastercard', 'category' => 'card', 'icon' => ''],
        ];
        $this->apiServiceMock->method('getEnabledPaymentMethods')->willReturn($enabledMethods);
        $this->apiServiceMock->method('getPaymentProductsCached')->willReturn(['success' => false]);

        // buildModel est appelé : 1 fois pour le groupe + 2 fois pour les options
        $callCount = 0;
        $this->modelFactoryMock
            ->method('buildModel')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                return new class {
                    public function __call(string $name, array $args): static { return $this; }
                    public function appendPaymentModuleOption(object $o): static { return $this; }
                };
            });

        $listener->onPaymentGetOptions($event);

        $this->assertCount(1, $event->getAppendedGroups(), 'Un seul groupe d\'options doit être ajouté à l\'événement');
        $this->assertSame(3, $callCount, 'buildModel doit être appelé 3 fois (1 groupe + 2 options)');
    }

    public function testOnPaymentGetOptionsUsesLogoFromApiWhenAvailable(): void
    {
        $listener = new PaymentOptionsListener($this->modelFactoryMock, $this->apiServiceMock);
        $event = $this->makeEvent('CawlPayment');

        $enabledMethods = [
            'visa' => ['id' => 1, 'name' => 'Visa', 'category' => 'card', 'icon' => ''],
        ];
        $this->apiServiceMock->method('getEnabledPaymentMethods')->willReturn($enabledMethods);
        $this->apiServiceMock->method('getPaymentProductsCached')->willReturn([
            'success' => true,
            'products' => [
                ['id' => 1, 'displayHints' => ['logo' => 'https://cdn.example.com/visa.png']],
            ],
        ]);

        $capturedImage = null;
        $this->modelFactoryMock
            ->method('buildModel')
            ->willReturnCallback(function (string $type) use (&$capturedImage) {
                return new class($type, $capturedImage) {
                    private string $type;
                    private mixed &$capturedImage;

                    public function __construct(string $type, mixed &$capturedImage)
                    {
                        $this->type = $type;
                        $this->capturedImage = &$capturedImage;
                    }

                    public function __call(string $name, array $args): static { return $this; }

                    public function setImage(string $url): static
                    {
                        if ($this->type === 'PaymentModuleOption') {
                            $this->capturedImage = $url;
                        }
                        return $this;
                    }

                    public function appendPaymentModuleOption(object $o): static { return $this; }
                };
            });

        $listener->onPaymentGetOptions($event);

        $this->assertSame(
            'https://cdn.example.com/visa.png',
            $capturedImage,
            'L\'URL du logo de l\'API doit être passée à l\'option'
        );
    }
}
