<?php

declare(strict_types=1);

/**
 * Zero-dependency PSR-4 autoloader for Shop Profit Intelligence.
 *
 * The analysis core is framework-free on purpose. It has no Composer
 * dependency, no Laravel dependency, and — by the isolation contract — no
 * dependency on the accounting application either. This file is what makes
 * that testable: the whole domain runs from a plain PHP script.
 */
$composer = __DIR__.'/vendor/autoload.php';
if (is_file($composer)) {
    require_once $composer;

    return;
}

spl_autoload_register(static function (string $class): void {
    foreach (['Shop\\Tests\\' => __DIR__.'/tests/', 'Shop\\' => __DIR__.'/src/'] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $path = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($path)) {
                require_once $path;
            }

            return;
        }
    }
});
