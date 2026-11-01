<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * PSR-4 style autoloader for Aicountly\Api\*, mapped onto server-php/src.
 *
 * There is no composer here on purpose: this API has no third-party
 * dependencies, and a vendor directory per product would be megabytes of
 * nothing for a deploy that is an rsync. It matches the rest of the fleet.
 */
spl_autoload_register(static function (string $class): void {
    // Aicountly\Api\*  -> src/*            this product's own code
    // Aicountly\Ops\*  -> src/Ops/*        the estate kit's shared class, which keeps its own
    //                                       namespace because the identical file is installed in
    //                                       every product (console-react-app ops/estate/kit).
    //
    // The kit's file is also require_once'd where it is used, because the products that dispatch
    // with a hand-written if-chain load it before any autoloader is registered. Mapping it here as
    // well is what makes it RESOLVABLE: tests/manage.php walks every source file and calls
    // class_exists() on each class it names, and a class no autoloader can find is a failure —
    // correctly, since a typo in that name would otherwise only show up as a 500 in production.
    foreach (['Aicountly\\Api\\' => __DIR__, 'Aicountly\\Ops\\' => __DIR__ . '/Ops'] as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path = $dir . '/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require $path;
        }

        return;
    }
});
