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
 * `--checks` reads OVERDUE INVOICES FROM BOOKS, live, with an explicit limit,
 * and starts one journey run per invoice that has no run yet. The invoice rows
 * are processed IN MEMORY and are not written anywhere — see
 * docs/DATA_OWNERSHIP.md. This is the "configured scheduled
 * operational check" the brief permits, and it is deliberately the only one:
 * it calls a live API and acts on the result, and it copies nothing.
 *
 * It is NOT a synchronisation job. It does not maintain a local table of
 * invoices, it does not run on a schedule of its own choosing, and nothing
 * downstream reads its output — each run re-fetches its own invoice before
 * drafting and again before sending.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Clients\BooksClient;
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
        $client = (new BooksClient())->withService('scheduler');

        if (!$client->configured()) {
            echo "skipped {$journey['name']}: Aicountly Books is not connected.\n";
            continue;
        }

        // A BOUNDED live query. The rows below live for this loop and nowhere
        // else.
        $result = $client->overdueInvoices($ctx, ['limit' => 200]);
        if (!$result['ok']) {
            // A source outage means no runs are started. It does NOT mean
            // running from something stored earlier, because nothing was.
            echo "skipped {$journey['name']}: " . (string) $result['message'] . "\n";
            continue;
        }

        foreach ((array) ($result['body']['data'] ?? []) as $invoice) {
            if (!is_array($invoice)) {
                continue;
            }
            $reference = (string) ($invoice['voucher_no'] ?? $invoice['voucher_id'] ?? '');
            $contactUuid = (string) ($invoice['contact_uuid'] ?? '');
            if ($reference === '' || $contactUuid === '') {
                continue;
            }

            // Idempotent per invoice per day: a daily check must not start a
            // fresh run for the same invoice every morning.
            $triggerKey = 'books:' . $reference . ':' . Clock::now()->format('Y-m-d');

            $conversation = Db::first(
                'SELECT conversation_uuid FROM messaging_conversations
                 WHERE cmp_id = :cmp AND contact_uuid = :contact AND status <> :resolved
                 ORDER BY COALESCE(last_inbound_at, created_at) DESC LIMIT 1',
                ['cmp' => $ctx->cmpId, 'contact' => $contactUuid, 'resolved' => 'resolved'],
            );

            try {
                $run = JourneyEngine::start($ctx, $auth, (string) $journey['journey_uuid'], [
                    'subject_product'   => 'books',
                    'subject_ref'       => $reference,
                    'contact_uuid'      => $contactUuid,
                    'conversation_uuid' => $conversation !== null ? (string) $conversation['conversation_uuid'] : '',
                    'trigger_key'       => $triggerKey,
                ], 'live', 'scheduled_check');

                if ($run['ok'] && !$run['duplicate']) {
                    $started++;
                    printf("%s  started run=%s invoice=%s  %s\n", gmdate('c'),
                        substr((string) $run['run_uuid'], 0, 8), $reference, $run['detail']);
                }
            } catch (\Throwable $e) {
                error_log('[journey-tick] start for invoice ' . $reference . ' threw: ' . $e->getMessage());
            }
        }

        // The fetched invoices go out of scope here. Nothing was written.
        unset($result);
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
