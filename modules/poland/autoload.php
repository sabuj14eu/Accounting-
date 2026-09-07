<?php

declare(strict_types=1);

/**
 * Zero-dependency PSR-4 autoloader for the Poland module.
 *
 * The tax engine is deliberately framework-free, and this file keeps it
 * runnable that way: from a plain script, from a cron job, or from the test
 * runner, with no vendor directory present. When Composer's autoloader is
 * available it is used instead.
 */

$composer = __DIR__.'/vendor/autoload.php';
if (is_file($composer)) {
    require_once $composer;

    return;
}

spl_autoload_register(static function (string $class): void {
    foreach (['Poland\\Tests\\' => __DIR__.'/tests/', 'Poland\\' => __DIR__.'/src/'] as $prefix => $base) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path = $base.str_replace('\\', '/', $relative).'.php';
        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
});
