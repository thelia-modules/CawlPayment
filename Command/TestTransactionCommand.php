<?php

declare(strict_types=1);

namespace CawlPayment\Command;

use CawlPayment\CawlPayment;
use CawlPayment\Service\CawlApiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thelia\Command\ContainerAwareCommand;

/**
 * Crée une transaction de test dans la sandbox CAWL sans nécessiter de commande Thelia.
 *
 * Usage :
 *   ddev exec php Thelia cawlpayment:test-transaction
 *   ddev exec php Thelia cawlpayment:test-transaction --amount=5000 --currency=EUR
 */
class TestTransactionCommand extends ContainerAwareCommand
{
    public function __construct(private readonly CawlApiService $cawlApiService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('cawlpayment:test-transaction')
            ->setDescription('Crée une transaction de test dans la sandbox CAWL (environnement dev/test uniquement)')
            ->addOption('amount', null, InputOption::VALUE_OPTIONAL, 'Montant en centimes (ex: 1000 = 10,00 €)', 1000)
            ->addOption('currency', null, InputOption::VALUE_OPTIONAL, 'Code devise ISO 4217', 'EUR');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('CawlPayment — Création d\'une transaction de test');

        if (!$this->runChecks($io)) {
            return Command::FAILURE;
        }

        $amount = (int) $input->getOption('amount');
        $currency = strtoupper((string) $input->getOption('currency'));

        $io->section(sprintf('Création du checkout de test (%d %s = %.2f %s)', $amount, $currency, $amount / 100, $currency));

        $result = $this->cawlApiService->createTestHostedCheckout($amount, $currency);

        if (!($result['success'] ?? false)) {
            $io->error('Échec de la création du checkout : ' . ($result['error'] ?? 'erreur inconnue'));
            return Command::FAILURE;
        }

        $io->success('Checkout de test créé avec succès !');

        // Prefer redirectUrl from the API response (CAWL-specific domain),
        // fall back to manually constructed checkoutUrl if not provided.
        $paymentUrl = (!empty($result['redirectUrl']) ? $result['redirectUrl'] : null)
            ?? $result['checkoutUrl']
            ?? '';

        $io->writeln('<info>URL du paiement :</info>');
        $io->writeln('  ' . $paymentUrl);
        $io->newLine();
        $io->writeln('<comment>Ouvrez cette URL dans votre navigateur pour simuler le paiement.</comment>');
        $io->writeln('<comment>Cartes de test Worldline : https://docs.direct.worldline-solutions.com/en/integration/test-cases</comment>');

        if (!empty($result['hostedCheckoutId'])) {
            $io->newLine();
            $io->writeln('<fg=gray>Hosted Checkout ID : ' . $result['hostedCheckoutId'] . '</>');
        }

        return Command::SUCCESS;
    }

    private function runChecks(SymfonyStyle $io): bool
    {
        $io->section('Vérifications préalables');

        // Check 1 — environnement Thelia (hard stop : ne jamais lancer en prod)
        $env = $this->getContainer()->getParameter('kernel.environment');
        if (\in_array($env, ['prod', 'production'], true)) {
            $io->error(sprintf(
                'Environnement Thelia : "%s" — Cette commande est réservée aux environnements dev/test.' . "\n"
                . 'Elle ne doit jamais être exécutée en production.',
                $env
            ));
            return false;
        }
        $io->writeln(sprintf('<info>✓</info> Environnement Thelia : %s', $env));

        // Checks 2–6 : accumulés pour tout afficher d'un coup
        $hasErrors = false;

        // Check 2 — module configuré sur "test"
        $moduleEnv = CawlPayment::getConfigValue('environment', CawlPayment::ENV_TEST);
        if ($moduleEnv !== CawlPayment::ENV_TEST) {
            $io->writeln(sprintf('<error>✗</error> Module configuré sur : %s', $moduleEnv));
            $io->writeln('  <comment>→ Admin Thelia > CawlPayment > Configuration > Environnement : passer sur "test"</comment>');
            $hasErrors = true;
        } else {
            $io->writeln('<info>✓</info> Module configuré sur : test (sandbox Worldline)');
        }

        // Check 3 — pspid
        $pspid = CawlPayment::getConfigValue('pspid');
        if (empty($pspid)) {
            $io->writeln('<error>✗</error> pspid non configuré');
            $io->writeln('  <comment>→ Admin Thelia > CawlPayment > Configuration > Identifiant marchand (PSPID)</comment>');
            $hasErrors = true;
        } else {
            $io->writeln(sprintf('<info>✓</info> pspid : %s', $pspid));
        }

        // Check 4 — api_key_test (valeur chiffrée présente)
        $apiKey = CawlPayment::getConfigValue('api_key_test');
        if (empty($apiKey)) {
            $io->writeln('<error>✗</error> api_key_test non configurée');
            $io->writeln('  <comment>→ Admin Thelia > CawlPayment > Configuration > Clé API (test)</comment>');
            $hasErrors = true;
        } else {
            $io->writeln('<info>✓</info> api_key_test : configurée');
        }

        // Check 5 — api_secret_test
        $apiSecret = CawlPayment::getConfigValue('api_secret_test');
        if (empty($apiSecret)) {
            $io->writeln('<error>✗</error> api_secret_test non configuré');
            $io->writeln('  <comment>→ Admin Thelia > CawlPayment > Configuration > Secret API (test)</comment>');
            $hasErrors = true;
        } else {
            $io->writeln('<info>✓</info> api_secret_test : configuré');
        }

        // Check 6 — méthodes activées
        $methods = CawlPayment::getConfigValue('enabled_methods', '');
        $methodList = array_filter(array_map('trim', explode(',', $methods)));
        if (empty($methodList)) {
            $io->writeln('<error>✗</error> Aucune méthode de paiement activée');
            $io->writeln('  <comment>→ Admin Thelia > CawlPayment > Configuration > Méthodes de paiement</comment>');
            $hasErrors = true;
        } else {
            $io->writeln(sprintf('<info>✓</info> Méthodes activées : %s', implode(', ', $methodList)));
        }

        if ($hasErrors) {
            $io->error('Configuration incomplète. Corrigez les points ci-dessus puis relancez la commande.');
            return false;
        }

        // Check 7 — connexion API live (hard stop si échec)
        $io->write('  Connexion API CAWL (appel sandbox)... ');
        $connResult = $this->cawlApiService->testConnection();

        if (!($connResult['success'] ?? false)) {
            $io->writeln('<error>KO</error>');
            $io->error(sprintf(
                'Connexion API échouée : %s' . "\n"
                . '→ Vérifiez api_key_test et api_secret_test dans la configuration du module.',
                $connResult['error'] ?? 'erreur inconnue'
            ));
            return false;
        }

        $endpoint = $connResult['endpoint'] ?? $this->cawlApiService->getApiUrl();
        $io->writeln(sprintf('<info>OK</info> (%s)', $endpoint));

        $io->newLine();
        $io->writeln('<info>Toutes les vérifications sont passées.</info>');

        return true;
    }
}
