<?php

declare(strict_types=1);

/**
 * Create the appointment notice templates for one company, as DRAFTS.
 *
 *   php server-php/bin/seed-appointment-templates.php --cmp=7                       what WOULD be created (default)
 *   php server-php/bin/seed-appointment-templates.php --cmp=7 --apply               create them
 *   php server-php/bin/seed-appointment-templates.php --cmp=7 --channels=whatsapp   one channel only
 *
 * Appointments sends four kinds of notice by template NAME — confirmation,
 * reminder, cancellation, reschedule — and Messaging refuses to send a name
 * that does not exist for the company and channel, or has no provider-approved
 * version. This script creates the missing ones with the agreed variable list
 * (Domain/AppointmentTemplates, and docs/APPOINTMENTS_MESSAGING_CONTRACT.md).
 *
 * ## What it does NOT do
 *
 *   - It does not submit anything to a provider and does not mark anything
 *     approved. Each template is saved as `not_submitted`. A WhatsApp utility
 *     template has to be submitted and approved by the provider; an SMS
 *     template on an Indian route needs its DLT registration first. Until then
 *     a send is refused `template_not_approved` and Appointments records the
 *     reminder as failed with that reason.
 *   - It does not touch a template that already exists (it may have been edited
 *     or approved since). Re-running is safe.
 *   - It does not write without --apply. The default prints the plan.
 *
 * Every created template appears in Templates in the Messaging UI, where an
 * administrator edits the wording and submits it.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Domain\AppointmentTemplates;

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
$auth = Auth::forProvider('appointment-template-seed');

$report = AppointmentTemplates::seed($ctx, $auth, $channels, $apply);

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
