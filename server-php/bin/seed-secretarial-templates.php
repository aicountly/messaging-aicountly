<?php

declare(strict_types=1);

/**
 * Create Secretarial's notice template (`secretarial_notice`) for one company, as a DRAFT.
 *
 *   php server-php/bin/seed-secretarial-templates.php --cmp=7                       what WOULD be created (default)
 *   php server-php/bin/seed-secretarial-templates.php --cmp=7 --apply               create it
 *   php server-php/bin/seed-secretarial-templates.php --cmp=7 --channels=whatsapp   one channel only
 *
 * Secretarial's notification ledger sends SMS / WhatsApp notices by this template
 * name with the variables in Domain/SecretarialTemplates. Nothing is submitted to a
 * provider or marked approved; an existing template is left alone; nothing is
 * written without --apply. The company must also allow `secretarial` under
 * Messaging settings (service_products) before any notice is accepted.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Domain\SecretarialTemplates;

$args = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$cmp = null;
$channels = ['whatsapp', 'sms'];

foreach ($args as $arg) {
    if (preg_match('/^--cmp=(\d+)$/', $arg, $m) === 1) {
        $cmp = (int) $m[1];
    } elseif (preg_match('/^--channels=([a-z,]+)$/', $arg, $m) === 1) {
        $channels = array_values(array_intersect(['whatsapp', 'sms', 'rcs'], explode(',', $m[1])));
    }
}

if ($cmp === null || $cmp <= 0) {
    fwrite(STDERR, "--cmp=<company id> is required: this script never acts on every company.\n");
    exit(2);
}
if ($channels === []) {
    fwrite(STDERR, "No valid channel in --channels (whatsapp, sms, rcs).\n");
    exit(2);
}

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the database: {$e->getMessage()}\n");
    exit(1);
}

$ctx = Context::forCompany($cmp);
$auth = Auth::forProvider('secretarial-template-seed');

$report = SecretarialTemplates::seed($ctx, $auth, $channels, $apply);

printf("%s for company %d\n", $apply ? 'APPLY' : 'DRY RUN (nothing written; add --apply)', $cmp);
$failed = 0;
foreach ($report as $row) {
    printf("  %-9s %-26s %s\n", $row['channel'], $row['name'], $row['action']);
    if (str_starts_with($row['action'], 'failed')) {
        $failed++;
    }
}
echo "\nNext: edit the wording in Messaging > Templates if needed, submit each template to the provider, "
    . "and wait for approval. Nothing is sent from these until the provider approves them.\n";

exit($failed > 0 ? 1 : 0);
