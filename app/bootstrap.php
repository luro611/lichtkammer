<?php
declare(strict_types=1);

// Eigener Autoloader (PSR-4 für Namespace Lichtkammer\), kein Composer nötig.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Lichtkammer\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
