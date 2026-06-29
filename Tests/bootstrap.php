<?php

declare(strict_types=1);

/**
 * Bootstrap file for PHPUnit tests
 *
 * Ce fichier configure l'environnement de test en chargeant l'autoloader
 * et en definissant les mocks necessaires pour les classes Thelia.
 */

// Register Propel generated base models BEFORE vendor autoload so they take precedence
// over Composer PSR-4 (which looks in the module dir and fails for generated files).
// Try multiple locations: sibling thelia dir (host real path) and DDEV paths.
$propelModelDirs = [
    dirname(__DIR__, 2) . '/thelia/var/cache/dev/propel/model/', // Sibling thelia (host)
    dirname(__DIR__, 4) . '/var/cache/dev/propel/model/',         // DDEV via symlink
    '/var/www/html/var/cache/dev/propel/model/',                   // DDEV hardcoded
];
foreach ($propelModelDirs as $propelModelDir) {
    if (is_dir($propelModelDir)) {
        spl_autoload_register(function (string $class) use ($propelModelDir): void {
            $file = $propelModelDir . str_replace('\\', '/', $class) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }, true, true);
        break;
    }
}

// Autoload from module's vendor or parent project
// dirname(__DIR__) resolves to the REAL path (symlinks followed), so we try multiple locations:
//  1. Module standalone vendor
//  2. Sibling 'thelia' directory (host real path: CawlPayment and thelia are siblings under docker/)
//  3. DDEV symlink path (4 levels up from symlink = /var/www/html)
//  4. DDEV hardcoded fallback
$vendorPaths = [
    dirname(__DIR__) . '/vendor/autoload.php',                   // Module standalone
    dirname(__DIR__, 2) . '/thelia/vendor/autoload.php',         // Sibling thelia (host)
    dirname(__DIR__, 4) . '/vendor/autoload.php',                 // DDEV via symlink
    '/var/www/html/vendor/autoload.php',                          // DDEV hardcoded
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

// Initialize Thelia\Core\Translation\Translator singleton for tests.
// $instance is untyped so we inject a minimal anonymous mock via Reflection.
if (class_exists('Thelia\Core\Translation\Translator')) {
    $translatorRef = new ReflectionClass(Thelia\Core\Translation\Translator::class);
    $instanceProp = $translatorRef->getProperty('instance');
    $instanceProp->setAccessible(true);
    if ($instanceProp->getValue(null) === null) {
        $instanceProp->setValue(null, new class {
            public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null, bool $returnDefaultIfNotAvailable = true): string
            {
                return (string) $id;
            }
        });
    }
}
