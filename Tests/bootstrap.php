<?php

declare(strict_types=1);

/**
 * Bootstrap file for PHPUnit tests
 *
 * Ce fichier configure l'environnement de test en chargeant l'autoloader
 * et en definissant les mocks necessaires pour les classes Thelia.
 */

// Autoload from module's vendor or parent project
$vendorPaths = [
    dirname(__DIR__) . '/vendor/autoload.php',           // Module vendor
    dirname(__DIR__, 4) . '/vendor/autoload.php',        // Parent project vendor
];

$autoloaderFound = false;
foreach ($vendorPaths as $vendorPath) {
    if (file_exists($vendorPath)) {
        require_once $vendorPath;
        $autoloaderFound = true;
        break;
    }
}

if (!$autoloaderFound) {
    // Fallback: register a simple PSR-4 autoloader for tests
    spl_autoload_register(function (string $class): void {
        $prefixes = [
            'CawlPayment\\Tests\\' => __DIR__ . '/',
            'CawlPayment\\' => dirname(__DIR__) . '/',
        ];

        foreach ($prefixes as $prefix => $baseDir) {
            $len = strlen($prefix);
            if (strncmp($prefix, $class, $len) === 0) {
                $relativeClass = substr($class, $len);
                $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
                if (file_exists($file)) {
                    require $file;
                    return;
                }
            }
        }
    });
}

// Register mock classes for Thelia dependencies
// These mocks allow tests to run without a full Thelia installation
// IMPORTANT: Use class_exists($class, false) to avoid triggering autoload

// Mock Thelia\Module\AbstractPaymentModule FIRST (needed by CawlPayment)
// This must be before any other class that might depend on it
if (!class_exists('Thelia\Module\AbstractPaymentModule', false)) {
    require_once __DIR__ . '/Mock/AbstractPaymentModuleMock.php';
    class_alias('CawlPayment\Tests\Mock\AbstractPaymentModuleMock', 'Thelia\Module\AbstractPaymentModule');
}

// Mock Thelia\Model\ConfigQuery if not available
if (!class_exists('Thelia\Model\ConfigQuery', false)) {
    require_once __DIR__ . '/Mock/ConfigQueryMock.php';
    class_alias('CawlPayment\Tests\Mock\ConfigQueryMock', 'Thelia\Model\ConfigQuery');
}

// Mock Thelia\Log\Tlog if not available
if (!class_exists('Thelia\Log\Tlog', false)) {
    require_once __DIR__ . '/Mock/TlogMock.php';
    class_alias('CawlPayment\Tests\Mock\TlogMock', 'Thelia\Log\Tlog');
}
