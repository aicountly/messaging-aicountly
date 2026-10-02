<?php

declare(strict_types=1);

/**
 * Resume journey runs whose delay has expired, and run scheduled checks.
 *
 *   php server-php/bin/journey-tick.php
 *   php server-php/bin/journey-tick.php --checks   also run the scheduled source checks
 *
 * ## What the scheduled check does, and what it must not do
 *
 * `--checks` starts unanswered-enquiry follow-ups from Messaging's own data.
 * Overdue-invoice reminders are NOT started here: Books is read only as a
 * signed-in person (no product-key access) and per Books ledger, so the
 * scheduler skips them and says so (G19#5).
 *
 * It is NOT a synchronisation job. It copies nothing from another product,
 * does not run on a schedule of its own choosing, and nothing downstream
 * reads its output (docs/DATA_OWNERSHIP.md).
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

// X-09: a CLI worker has no Host, and "no Host" used to mean sandbox — so a
// production worker read sandbox siblings. The environment now comes from
// AIC_ENVIRONMENT (else APP_ENV) only, and with neither this refuses to run.
if (Environment::current() === null) {
    fwrite(STDERR, Environment::explainUnconfigured() . "\n");
    exit(1);
}

use Aicountly\Api\Domain\JourneyEngine;
use Aicountly\Api\Domain\JourneyService;
use Aicountly\Api\Support\Clock;

$args = array_slice($argv, 1);
$runChecks = in_array('--checks', $args, true);

try {
    Db::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the database: {$e->getMessage()}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 1. Resume runs whose delay has expired.
// ---------------------------------------------------------------------------

$due = Db::all(
    "SELECT run_uuid, cmp_id, bo_id FROM messaging_journey_runs
     WHERE status IN ('running', 'paused')
       AND resume_after IS NOT NULL
       AND resume_after <= NOW()
     ORDER BY resume_after
     LIMIT 100",
);

$resumed = 0;
foreach ($due as $row) {
    $ctx = Context::forCompany((int) $row['cmp_id'], (int) ($row['bo_id'] ?? 0));
    // A service actor: this is the scheduler acting, not a person. It carries
    // no ses_key, so cross-product reads go out under Messaging's own service
    // key rather than a borrowed human session.
    $auth = Auth::forProvider('scheduler');

    try {
        $result = JourneyEngine::resume($ctx, $auth, (string) $row['run_uuid']);
        $resumed++;
        printf("%s  resumed run=%s status=%s  %s\n", gmdate('c'),
            substr((string) $row['run_uuid'], 0, 8), $result['status'], $result['detail']);
    } catch (\Throwable $e) {
        error_log('[journey-tick] resume ' . (string) $row['run_uuid'] . ' threw: ' . $e->getMessage());
        fwrite(STDERR, "FAILED resume run={$row['run_uuid']}: {$e->getMessage()}\n");
    }
}

echo "resumed {$resumed} run(s)\n";

if (!$runChecks) {
    exit(0);
}

// ---------------------------------------------------------------------------
// 2. Scheduled operational checks.
//
// One per published journey of a kind that has a source to poll. Nothing is
// stored from the poll.
// ---------------------------------------------------------------------------

$journeys = Db::all(
    "SELECT journey_uuid, cmp_id, bo_id, kind, name FROM messaging_journeys
     WHERE status = 'published' AND published_version IS NOT NULL
       AND kind IN ('overdue_invoice_reminder', 'unanswered_enquiry_followup')",
);

$started = 0;

foreach ($journeys as $journey) {
    $ctx = Context::forCompany((int) $journey['cmp_id'], (int) ($journey['bo_id'] ?? 0));
    $auth = Auth::forProvider('scheduler');
    $kind = (string) $journey['kind'];

    if ($kind === 'overdue_invoice_reminder') {
        // G19#5: Books is read as a signed-in person — it has no product-key
        // access — and it reports dues per Books ledger, which is a customer
        // only where Contacts holds an explicit reference. The scheduler has
        // neither a person nor a session, so it does not pretend: it used to
        // ask Books with a key Books does not accept and no ledger, and read
        // the refusal as "nobody is overdue". Overdue reminders are started by
        // a person (journey simulation/run from the screen) until Books offers
        // a delegated read.
        echo "skipped {$journey['name']}: Aicountly Books is read as a signed-in person and has no product-key "
            . "access, so the scheduler cannot list overdue invoices. Start this journey from Messaging while signed in.\n";
        continue;
    }

    if ($kind === 'unanswered_enquiry_followup') {
        // Messaging's OWN data, so no cross-product call at all.
        $conversations = Db::all(
            "SELECT conversation_uuid, contact_uuid FROM messaging_conversations
             WHERE cmp_id = :cmp
               AND status <> 'resolved'
               AND last_inbound_at IS NOT NULL
               AND (last_outbound_at IS NULL OR last_outbound_at < last_inbound_at)
               AND last_inbound_at < NOW() - INTERVAL '4 hours'
             ORDER BY last_inbound_at LIMIT 200",
            ['cmp' => $ctx->cmpId],
        );

        foreach ($conversations as $conversation) {
            $conversationUuid = (string) $conversation['conversation_uuid'];
            $triggerKey = 'conv:' . $conversationUuid . ':' . Clock::now()->format('Y-m-d');

            try {
                $run = JourneyEngine::start($ctx, $auth, (string) $journey['journey_uuid'], [
                    'subject_product'   => 'messaging',
                    'subject_ref'       => $conversationUuid,
                    'contact_uuid'      => (string) ($conversation['contact_uuid'] ?? ''),
                    'conversation_uuid' => $conversationUuid,
                    'trigger_key'       => $triggerKey,
                ], 'live', 'scheduled_check');

                if ($run['ok'] && !$run['duplicate']) {
                    $started++;
                }
            } catch (\Throwable $e) {
                error_log('[journey-tick] follow-up start threw: ' . $e->getMessage());
            }
        }
    }
}

echo "started {$started} run(s)\n";
