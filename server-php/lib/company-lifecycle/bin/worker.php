#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * The lifecycle worker and its operator commands. CLI only.
 *
 *   php worker.php --env=/path/api/.env --app=books --manifest=/path/lifecycle-manifest.php     # run due purges + acks (cron)
 *   php worker.php --bootstrap=/path/lifecycle-kit.php                                          # same, with the product's Kit (hooks, own PDO)
 *        [--jobs=5] [--acks=50]
 *   … --status                                   jobs and acks by status, acks Manage refused / gave up on
 *   … --dry-run=company:42 | --dry-run=account:1234   what a purge would archive / delete / retain (changes nothing)
 *   … --requeue=<event_id>                       run a finished (failed) purge again after a fix; Manage takes the later purged
 *   … --list-archives
 *   … --drop-archives-older-than=90 [--confirm]  archive schemas hold personal data: drop them after the product's archive window
 *   … --restore=<archive schema> [--confirm]     put an archive back (dry run without --confirm)
 *   … --install-schema                           apply schema.sql (when the product has no migration runner)
 *
 * --bootstrap names a PHP file that returns an Aicountly\Lifecycle\Kit (see examples/). Otherwise the Kit
 * is built from --env (the product's .env; real environment variables win), --app (or LIFECYCLE_APP_KEY)
 * and --manifest (or LIFECYCLE_MANIFEST). Prints one JSON line; exit 1 on an error.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/autoload.php';

use Aicountly\Lifecycle\Kit;

$o = getopt('', ['env::', 'app::', 'manifest::', 'bootstrap::', 'jobs::', 'acks::', 'status', 'dry-run::', 'requeue::',
    'list-archives', 'drop-archives-older-than::', 'restore::', 'confirm', 'install-schema', 'help']);
if (isset($o['help'])) {
    fwrite(STDERR, "see the header of bin/worker.php\n");
    exit(2);
}

try {
    if (!empty($o['bootstrap'])) {
        $kit = require (string) $o['bootstrap'];
        if (!$kit instanceof Kit) {
            throw new RuntimeException('--bootstrap must return an Aicountly\\Lifecycle\\Kit');
        }
    } else {
        $env = Kit::envFile(isset($o['env']) ? (string) $o['env'] : null);
        $app = (string) ($o['app'] ?? ($env('LIFECYCLE_APP_KEY') ?? ''));
        if ($app === '') {
            throw new RuntimeException('--app (or LIFECYCLE_APP_KEY) is required');
        }
        $kit = Kit::fromEnv($app, null, isset($o['manifest']) ? (string) $o['manifest'] : null, $env);
    }

    $out = ['app' => $kit->config->appKey, 'at' => gmdate('Y-m-d\TH:i:s\Z')];
    if (isset($o['install-schema'])) {
        $kit->store()->installSchema();
        $out['schema'] = 'installed';
    } elseif (isset($o['status'])) {
        $out += $kit->store()->summary();
        $out['configured'] = ['secret' => $kit->config->secretUsable(), 'manage_api_url' => $kit->config->manageUrl, 'service_key' => $kit->config->serviceKey !== null];
    } elseif (!empty($o['dry-run'])) {
        [$kind, $target] = array_pad(explode(':', (string) $o['dry-run'], 2), 2, '');
        $engine = $kit->engine() ?? throw new RuntimeException('no manifest (--manifest or LIFECYCLE_MANIFEST)');
        $out['dry_run'] = $engine->purge($kind === 'account' ? 'user' : 'company', $target, true);
    } elseif (!empty($o['requeue'])) {
        $out['requeued'] = $kit->store()->requeueJob((string) $o['requeue']);
    } elseif (isset($o['list-archives'])) {
        $out['archives'] = ($kit->engine() ?? throw new RuntimeException('no manifest'))->archives();
    } elseif (isset($o['drop-archives-older-than'])) {
        $engine = $kit->engine() ?? throw new RuntimeException('no manifest');
        $days = max(1, (int) $o['drop-archives-older-than']);
        $cut = time() - $days * 86400;
        $old = array_values(array_filter($engine->archives(), static fn (array $a): bool => strtotime($a['created_at']) < $cut));
        $out['older_than_days'] = $days;
        $out['archives'] = array_column($old, 'schema');
        if (isset($o['confirm'])) {
            foreach ($old as $a) {
                $engine->dropArchive($a['schema']);
            }
            $out['dropped'] = count($old);
        } else {
            $out['note'] = 'dry run: add --confirm to drop them';
        }
    } elseif (!empty($o['restore'])) {
        $engine = $kit->engine() ?? throw new RuntimeException('no manifest');
        $out['restore'] = $engine->restore((string) $o['restore'], isset($o['confirm']));
    } else {
        $out['run'] = $kit->worker()->run(max(0, (int) ($o['jobs'] ?? 5)), max(0, (int) ($o['acks'] ?? 50)));
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'company-lifecycle worker: ' . $e->getMessage() . "\n");
    exit(1);
}
