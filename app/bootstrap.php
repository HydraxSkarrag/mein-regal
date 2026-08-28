<?php
/**
 * Single entry point for wiring up the application.
 *
 * Included by public/index.php and by the scripts in bin/. Deliberately tiny:
 * an autoloader, the configuration and the database handle. No framework, no
 * service container - see the framework decision in the project plan.
 */
declare(strict_types=1);

const APP_ROOT = __DIR__;
const PROJECT_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, 4));
    $file = APP_ROOT . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/Core/helpers.php';
