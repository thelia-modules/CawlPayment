<?php

declare(strict_types=1);

namespace CawlPayment\Form;

use CawlPayment\CawlPayment;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\NotBlank;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

/**
 * Configuration form for CAWL Payment module
 */
class ConfigurationForm extends BaseForm
{
    protected function trans(string $str, array $params = []): string
    {
        return Translator::getInstance()->trans($str, $params, CawlPayment::DOMAIN_NAME);
    }

    protected function buildForm(): void
    {
        $this->formBuilder
            // === CREDENTIALS TAB ===
            ->add('pspid', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->trans('PSPID (Merchant ID)'),
                'label_attr' => ['help' => $this->trans('Your CAWL merchant identifier')],
                'required' => true,
            ])

            // Test environment
            ->add('api_key_test', PasswordType::class, [
                'label' => $this->trans('API Key (Test)'),
                'label_attr' => ['help' => $this->trans('API Key for test environment')],
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('api_secret_test', PasswordType::class, [
                'label' => $this->trans('API Secret (Test)'),
                'label_attr' => ['help' => $this->trans('API Secret for test environment')],
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
            ])

            // Production environment
            ->add('api_key_prod', PasswordType::class, [
                'label' => $this->trans('API Key (Production)'),
                'label_attr' => ['help' => $this->trans('API Key for production environment')],
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
            ])
            ->add('api_secret_prod', PasswordType::class, [
                'label' => $this->trans('API Secret (Production)'),
                'label_attr' => ['help' => $this->trans('API Secret for production environment')],
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
            ])

            // Environment toggle
            ->add('environment', ChoiceType::class, [
                'choices' => [
                    $this->trans('Test') => CawlPayment::ENV_TEST,
                    $this->trans('Production') => CawlPayment::ENV_PRODUCTION,
                ],
                'label' => $this->trans('Active Environment'),
                'label_attr' => ['help' => $this->trans('Select the active payment environment')],
                'required' => true,
            ])

            // Webhook keys (Test)
            ->add('webhook_key_test', TextType::class, [
                'label' => $this->trans('Webhook Key (Test)'),
                'label_attr' => ['help' => $this->trans('Webhook Key ID for test environment')],
                'required' => false,
            ])
            ->add('webhook_secret_test', PasswordType::class, [
                'label' => $this->trans('Webhook Secret (Test)'),
                'label_attr' => ['help' => $this->trans('Webhook Secret for test environment')],
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
            ])

            // Webhook keys (Production)
            ->add('webhook_key_prod', TextType::class, [
                'label' => $this->trans('Webhook Key (Production)'),
                'label_attr' => ['help' => $this->trans('Webhook Key ID for production environment')],
                'required' => false,
            ])
            ->add('webhook_secret_prod', PasswordType::class, [
                'label' => $this->trans('Webhook Secret (Production)'),
                'label_attr' => ['help' => $this->trans('Webhook Secret for production environment')],
                'required' => false,
                'attr' => ['autocomplete' => 'new-password'],
            ])

            // === PAYMENT METHODS TAB ===
            ->add('enabled_methods', TextareaType::class, [
                'label' => $this->trans('Enabled Payment Methods'),
                'label_attr' => ['help' => $this->trans('Comma-separated list of enabled method codes')],
                'required' => false,
            ])

            // === OPTIONS TAB ===
            ->add('enable_logging', CheckboxType::class, [
                'label' => $this->trans('Enable detailed logging'),
                'label_attr' => ['help' => $this->trans('Log all API requests and responses for debugging')],
                'required' => false,
            ])

            ->add('checkout_description', TextType::class, [
                'label' => $this->trans('Checkout description'),
                'label_attr' => ['help' => $this->trans('Custom text displayed at checkout')],
                'required' => false,
            ])

            ->add('min_amount', NumberType::class, [
                'label' => $this->trans('Minimum amount'),
                'label_attr' => ['help' => $this->trans('Minimum order amount to display this payment method (0 = no minimum)')],
                'required' => false,
                'scale' => 2,
            ])

            ->add('max_amount', NumberType::class, [
                'label' => $this->trans('Maximum amount'),
                'label_attr' => ['help' => $this->trans('Maximum order amount to display this payment method (0 = no maximum)')],
                'required' => false,
                'scale' => 2,
            ])

            // === SECURITY TAB ===
            ->add('webhook_whitelist_enabled', CheckboxType::class, [
                'label' => $this->trans('Enable IP whitelist for webhooks'),
                'label_attr' => ['help' => $this->trans('When disabled in test mode, all IPs are allowed')],
                'required' => false,
            ])

            ->add('webhook_ip_whitelist', TextareaType::class, [
                'label' => $this->trans('Webhook IP Whitelist'),
                'label_attr' => ['help' => $this->trans('Comma-separated list of allowed IPs or CIDR ranges (e.g., 192.168.1.1, 10.0.0.0/8)')],
                'required' => false,
                'attr' => ['rows' => 3],
            ])
        ;
    }

    public static function getName(): string
    {
        return 'cawlpayment_configuration';
    }
}
