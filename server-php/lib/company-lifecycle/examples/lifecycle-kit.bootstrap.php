<?php
// For bin/worker.php --bootstrap=…: the product's own Kit — its PDO (e.g. when DB names come from
// Console), hooks for data outside the database, and its audit trail. Return the Kit.
require_once __DIR__ . '/../lib/company-lifecycle/autoload.php';

use Aicountly\Lifecycle\Kit;

$env = Kit::envFile(__DIR__ . '/../.env');

return Kit::fromEnv('example', null, __DIR__ . '/../config/lifecycle-manifest.php', $env, [], [
    'hooks' => [
        // Return a line for the ack detail, or throw to fail the attempt (retried, then acked failed).
        // The third argument (kit 1.1) is an Aicountly\Lifecycle\HookContext.
        'company' => [static function (int|string $cmpId, array $result, $ctx): ?string {
            $dir = '/home/example/uploads/c' . (int) $cmpId;
            // … move $dir to the archive area / delete it …
            // Statutory data outside the manifest's tables goes into the ack's retained[]:
            // $ctx->retain('workspace:registers', 'Companies Act 2013 s.88: kept permanently', $count);
            return null;
        }],
        'account' => [static function (int|string $uuid, array $result, $ctx): ?string {
            // Rows changed outside the manifest are archived FIRST, so --restore puts them back:
            $ctx->archiveRows('shared_comments', 'author_uuid = ?', [(string) $uuid]);
            $ctx->pdo()->prepare("UPDATE shared_comments SET author_uuid = NULL, author_name = 'Deleted user' WHERE author_uuid = ?")->execute([(string) $uuid]);
            // … unregister push tokens at the provider, delete the person's personal files …
            return null;
        }],
        // 1.1: before the engine's purge (empty $result), e.g. collect object keys the purge will orphan.
        'company_before' => [],
        'account_before' => [],
    ],
    // 1.1: a reason stops the purge (Manage is acked `held`; looked at again daily).
    'legal_hold' => static function (string $scope, int|string $id): ?string {
        // return the hold's reference, e.g. 'Notice u/s 132, 12 Dec 2026 (ticket LH-7)', or null
        return null;
    },
    'on_finish' => static function (array $job, string $status, string $detail): void {
        // … write the product's audit trail row (who asked: AICOUNTLY Manage, event $job['event_id']) …
    },
]);
