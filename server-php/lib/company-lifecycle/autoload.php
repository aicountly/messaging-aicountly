<?php
declare(strict_types=1);

/**
 * AICOUNTLY company-lifecycle receiver kit — no Composer needed. require_once this file from the
 * product's bootstrap (or map the Aicountly\Lifecycle\ prefix to src/ in its own autoloader).
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Aicountly\\Lifecycle\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
