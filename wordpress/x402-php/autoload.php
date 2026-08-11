<?php

/**
 * Composer-free autoloader, so the library drops into WordPress and other
 * projects that do not run Composer.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'X402\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
