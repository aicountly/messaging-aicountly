<?php
// For bin/worker.php --bootstrap=…: the product's own Kit — its PDO (e.g. when DB names come from
// Console), hooks for data outside the database, and its audit trail. Return the Kit.
require_once __DIR__ . '/../lib/company-lifecycle/autoload.php';

use Aicountly\Lifecycle\Kit;

$env = Kit::envFile(__DIR__ . '/../.env');

return Kit::fromEnv('example', null, __DIR__ . '/../config/lifecycle-manifest.php', $env, [], [
    'hooks' => [
        // Return a line for the ack detail, or throw to fail the attempt (retried, then acked failed).
        'company' => [static function (int|string $cmpId, array $result): ?string {
            $dir = '/home/example/uploads/c' . (int) $cmpId;
            // … move $dir to the archive area / delete it …
            return null;
        }],
        'account' => [static function (int|string $uuid, array $result): ?string {
            // … unregister push tokens at the provider, delete the person's personal files …
            return null;
        }],
    ],
    'on_finish' => static function (array $job, string $status, string $detail): void {
        // … write the product's audit trail row (who asked: AICOUNTLY Manage, event $job['event_id']) …
    },
]);
