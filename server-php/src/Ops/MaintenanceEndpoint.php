<?php
declare(strict_types=1);

namespace Aicountly\Api\Ops;

use Aicountly\Api\Http;

require_once __DIR__ . '/MaintenanceControl.php';

use Aicountly\Ops\MaintenanceControl;

/**
 * Maintenance endpoint — plain-PHP family (src/, Aicountly\Api).
 *
 * CANONICAL COPY: console-react-app/ops/estate/kit/php/adapters/MaintenanceEndpoint.plain-php.php
 * COPY, DON'T EDIT. Installed as src/Ops/MaintenanceEndpoint.php.
 *
 * Register beside the other internal receivers in src/Routes.php:
 *
 *   $router->post('internal/maintenance', [Ops\MaintenanceEndpoint::class, 'handle']);
 *   $router->get('internal/maintenance',  [Ops\MaintenanceEndpoint::class, 'handle']);
 *
 * MaintenanceControl is required by path, not autoloaded: this family's autoloader maps
 * Aicountly\Api\* onto src/ and nothing else, and the one file the estate shares verbatim across
 * four different backend shapes should not have to live under a per-product namespace to be found.
 *
 * All the behaviour lives in that class, identical in every product and tested once. This file is
 * only the glue: pull the method, the Authorization header and the raw body out of the request, and
 * hand the answer to the house response helper.
 */
final class MaintenanceEndpoint
{
    public static function handle(): void
    {
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '');

        // Some cPanel/Apache setups strip Authorization before PHP sees it; apache_request_headers
        // still has it. Without this the endpoint would 401 on exactly those hosts and look like a
        // wrong key rather than a stripped header.
        if ($auth === '' && \function_exists('apache_request_headers')) {
            $headers = \array_change_key_case((array) \apache_request_headers());
            $auth = (string) ($headers['authorization'] ?? '');
        }

        [$status, $body] = (new MaintenanceControl())->handle(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $auth,
            (string) \file_get_contents('php://input'),
        );

        Http::json($status, $body);
    }
}
