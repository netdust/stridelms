<?php

declare(strict_types=1);

/**
 * Stride Core PSR-4 Autoloader
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'Stride\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
