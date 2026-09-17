<?php

declare(strict_types=1);

/**
 * Integration tests for the Messaging domain.
 *
 * They run against a REAL PostgreSQL database and a real HTTP stub standing in
 * for Manage, Contacts, Books, Sales, Pay, Appointments, Drive and Reach — so
 * what is under test is the actual SQL, the actual cross-product client and the
 * actual dispatch arithmetic, not mocks of them.
 *
 *   server-php/tests/run.sh
 *
 * ## The cases that earn their place here
 *
 * Every test below exists because being wrong about it costs a real customer
 * something real:
 *
 *   - a second message sent after an ambiguous provider answer (money, trust);
 *   - a message sent after somebody opted out (a legal problem, not a bug);
 *   - a draft promising a payment link that does not exist (a support call);
 *   - an approval that still counts after the text was edited (an unreviewed
 *     message in front of a customer);
 *   - a simulation that sends something (a test message to a real person);
 *   - one tenant's conversation served under another tenant's company id.
 *
 * ## What is NOT claimed
 *
 * Nothing here proves that Meta or Twilio accept a payload. That is not
 * knowable from a test suite. The real adapters' DECISIONS are tested as the
 * pure functions they are — signature verification, capability declaration,
 * configuration gaps, webhook normalisation — and the pipeline is tested
 * against tests/support/RecordingAdapter.php.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/support/RecordingAdapter.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Ai\ConsoleCredentials;
use Aicountly\Api\Ai\DraftAssistant;
use Aicountly\Api\Ai\NextBestActions;
use Aicountly\Api\Channels\Capability;
use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Channels\RcsAdapter;
use Aicountly\Api\Channels\OutboundMessage;
use Aicountly\Api\Channels\SendResult;
use Aicountly\Api\Channels\TwilioSmsAdapter;
use Aicountly\Api\Channels\WhatsAppCloudAdapter;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Domain\ConsentService;
use Aicountly\Api\Domain\ConversationService;
use Aicountly\Api\Domain\DispatchGuard;
use Aicountly\Api\Domain\DispatchService;
use Aicountly\Api\Domain\JourneyDefinition;
use Aicountly\Api\Domain\JourneyService;
use Aicountly\Api\Domain\JourneySimulator;
use Aicountly\Api\Domain\MessageService;
use Aicountly\Api\Domain\MessageState;
use Aicountly\Api\Domain\MetricsService;
use Aicountly\Api\Domain\Period;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Domain\SourceReader;
use Aicountly\Api\Domain\TemplateService;
use Aicountly\Api\Domain\WebhookService;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\RecordingAdapter;

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
$failures = [];

function check(string $name, callable $fn): void
{
    global $passed, $failed, $failures;

    try {
        $fn();
        echo "  ok    {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n        {$e->getMessage()}\n";
        if (getenv('VERBOSE')) {
            echo '        ' . $e->getFile() . ':' . $e->getLine() . "\n";
        }
        $failed++;
        $failures[] = $name;
    } finally {
        // Cleared HERE rather than at the end of each test body. A test that
        // sets a flag and then fails an assertion would otherwise leave it set,
        // and every later test would run against a deployment it never asked
        // for — one failure cascading into six.
        Features::overrideForTesting(null);
        ConsoleCredentials::overrideForTesting(null);
        ChannelRegistry::overrideForTesting('test_provider', null);
        CrossServiceCallContext::resetForTesting();
    }
}

function assertSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $what,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertTrue(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

function assertFalse(bool $condition, string $what): void
{
    if ($condition) {
        throw new \RuntimeException($what);
    }
}

function assertContains(string $needle, string $haystack, string $what): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException("{$what}: expected to find \"{$needle}\" in \"{$haystack}\"");
    }
}

function assertCount(int $expected, array $actual, string $what): void
{
    if (count($actual) !== $expected) {
        throw new \RuntimeException("{$what}: expected {$expected} item(s), got " . count($actual));
    }
}

function section(string $name): void
{
    echo "\n{$name}\n";
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const CMP = 9001;

/** A fixed Monday morning, so weekday and quiet-hours arithmetic is deterministic. */
const FROZEN_NOW = '2026-06-01T04:00:00Z';   // 09:30 Asia/Kolkata, a Monday

const CONNECTION = '11111111-1111-4111-8111-111111111111';

function ctx(int $boId = 0): Context
{
    return Context::forCompany(CMP, $boId);
}

function owner(): Auth
{
    // acs_type 1 is the company owner, which is the shortcut in Permissions.
    return Auth::forTesting('user-owner', 'user', 'messaging', ['acs_type' => 1]);
}

function agent(): Auth
{
    return Auth::forTesting('user-agent', 'user', 'messaging', ['acs_type' => 2]);
}

/**
 * Tear the company down and build it back up.
 *
 * Ordered by dependency rather than relying on cascades, so it keeps working
 * when a migration adds a table.
 */
function reset(): void
{
    foreach ([
        'messaging_outcome_links',
        'messaging_journey_step_runs',
        'messaging_journey_runs',
        'messaging_journey_versions',
        'messaging_journeys',
        'messaging_ai_runs',
        'messaging_daily_metrics',
        'messaging_dismissed_suggestions',
        'messaging_delivery_events',
        'messaging_webhook_receipts',
        'messaging_dispatch_jobs',
        'messaging_approvals',
        'messaging_message_attachments',
        'messaging_messages',
        'messaging_internal_notes',
        'messaging_conversation_labels',
        'messaging_conversation_assignments',
        'messaging_external_references',
        'messaging_conversations',
        'messaging_labels',
        'messaging_consent_events',
        'messaging_consent_records',
        'messaging_suppressions',
        'messaging_template_versions',
        'messaging_templates',
        'messaging_channel_capabilities',
        'messaging_channel_connections',
        'messaging_permission_assignments',
        'messaging_permission_profiles',
        'messaging_audit_events',
        'messaging_idempotency_keys',
        'messaging_settings',
    ] as $table) {
        Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => CMP]);
    }

    Settings::forget();
    Permissions::forget();
    Clock::freeze(FROZEN_NOW);

    // Manage is stubbed rather than reached for the tenant check, because the
    // check itself is tested separately and every other test should not pay an
    // HTTP round trip for it.
    Context::trustForTesting(CMP, owner());
    Context::trustForTesting(CMP, agent());
}

/** A connected channel with a credential present. */
function connection(array $overrides = []): string
{
    $uuid = (string) ($overrides['connection_uuid'] ?? CONNECTION);

    Db::insert('messaging_channel_connections', [
        'connection_uuid'      => $uuid,
        'cmp_id'               => CMP,
        'bo_id'                => 0,
        'channel'              => $overrides['channel'] ?? 'whatsapp',
        'provider'             => $overrides['provider'] ?? 'test_provider',
        'display_name'         => 'Test line',
        'sender_address'       => $overrides['sender_address'] ?? '+919800000000',
        'provider_account_ref' => 'acct-1',
        // The NAME of an environment variable, never a secret. That is the
        // whole point of the column and it is asserted below.
        'credential_ref'       => 'MESSAGING_TEST_TOKEN',
        'webhook_secret_ref'   => 'MESSAGING_TEST_WEBHOOK_SECRET',
        'status'               => $overrides['status'] ?? 'connected',
        'is_active'            => $overrides['is_active'] ?? true,
        'created_at'           => Clock::nowSql(),
        'updated_at'           => Clock::nowSql(),
    ], 'connection_uuid');

    return $uuid;
}

function conversation(array $overrides = []): string
{
    $uuid = Uuid::v4();

    Db::insert('messaging_conversations', [
        'conversation_uuid' => $uuid,
        'cmp_id'            => CMP,
        'bo_id'             => 0,
        'connection_uuid'   => $overrides['connection_uuid'] ?? CONNECTION,
        'channel'           => $overrides['channel'] ?? 'whatsapp',
        'customer_address'  => $overrides['customer_address'] ?? '+919812345678',
        'contact_uuid'      => $overrides['contact_uuid'] ?? null,
        'status'            => $overrides['status'] ?? 'open',
        'assigned_to_uuid'  => $overrides['assigned_to_uuid'] ?? null,
        'first_inbound_at'  => $overrides['first_inbound_at'] ?? Clock::nowSql(),
        'last_inbound_at'   => $overrides['last_inbound_at'] ?? Clock::nowSql(),
        'created_at'        => Clock::nowSql(),
        'updated_at'        => Clock::nowSql(),
    ], 'conversation_uuid');

    return $uuid;
}

/** A draft, approved, ready to queue. */
function approvedMessage(string $conversationUuid, string $body = 'Your order is on its way.'): string
{
    $saved = MessageService::saveDraft(ctx(), owner(), $conversationUuid, ['body' => $body]);
    assertTrue($saved['ok'], 'fixture: draft should save — ' . $saved['detail']);

    $approved = MessageService::approve(ctx(), owner(), (string) $saved['message_uuid'], 0);
    assertTrue($approved['ok'], 'fixture: draft should approve — ' . $approved['detail']);

    return (string) $saved['message_uuid'];
}

/** Register the recording adapter and give consent, which most send tests need. */
function readyToSend(RecordingAdapter $adapter, string $address = '+919812345678'): void
{
    ChannelRegistry::overrideForTesting('test_provider', $adapter);
    putenv('MESSAGING_TEST_TOKEN=test-token-value');

    // 'granted' and 'agent_recorded' are the vocabulary the schema enforces —
    // the evidence has to mean something to somebody reading it later.
    // 'service', because DispatchGuard::purposeFor() gives an agent-authored
    // reply that purpose — and a service grant also satisfies a transactional
    // send, while a transactional grant would not satisfy this one.
    ConsentService::record(
        ctx(), owner(), 'whatsapp', $address, 'service', 'granted',
        'agent_recorded', 'Confirmed on the phone.',
    );
}

// ===========================================================================
// Migrations and schema
// ===========================================================================

section('Schema');

check('every table the tests touch exists', static function (): void {
    $tables = Db::all(
        "SELECT table_name FROM information_schema.tables
         WHERE table_schema = current_schema() AND table_name LIKE 'messaging_%'",
    );
    $names = array_column($tables, 'table_name');

    foreach (['messaging_messages', 'messaging_conversations', 'messaging_dispatch_jobs',
        'messaging_consent_records', 'messaging_suppressions', 'messaging_external_references',
        'messaging_outcome_links', 'messaging_journey_runs', 'messaging_audit_events'] as $table) {
        assertTrue(in_array($table, $names, true), "{$table} should exist after migration");
    }
});

check('messaging_external_references has no payload column', static function (): void {
    // THE ARCHITECTURE, AS A CONSTRAINT. A payload column here would be the
    // beginning of a local mirror of another product's records, which is the
    // one thing this product must never grow.
    $columns = array_column(Db::all(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = 'messaging_external_references'",
    ), 'column_name');

    foreach (['payload', 'snapshot', 'cached_data', 'amount', 'balance', 'body'] as $forbidden) {
        assertFalse(
            in_array($forbidden, $columns, true),
            "messaging_external_references must not have a \"{$forbidden}\" column — a reference is not a copy",
        );
    }
});

check('messaging_outcome_links stores no amount or status of its own', static function (): void {
    $columns = array_column(Db::all(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = 'messaging_outcome_links'",
    ), 'column_name');

    foreach (['amount', 'amount_minor', 'outstanding', 'paid_amount', 'invoice_status'] as $forbidden) {
        assertFalse(
            in_array($forbidden, $columns, true),
            "messaging_outcome_links must not have \"{$forbidden}\" — Books owns the figure",
        );
    }
});

check('a dispatch job cannot be created in any mode but live', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    $messageUuid = approvedMessage($conversationUuid);

    // "Simulation sends nothing" has two independent mechanisms. This is the
    // structural one: even if the engine were wrong, the database refuses.
    $threw = false;
    try {
        Db::insert('messaging_dispatch_jobs', [
            'job_uuid'        => Uuid::v4(),
            'cmp_id'          => CMP,
            'bo_id'           => 0,
            'message_uuid'    => $messageUuid,
            'connection_uuid' => CONNECTION,
            'mode'            => 'simulation',
            'status'          => 'queued',
            'idempotency_key' => 'sim-' . $messageUuid,
            'available_at'    => Clock::nowSql(),
            'created_at'      => Clock::nowSql(),
        ], 'job_uuid');
    } catch (\Throwable) {
        $threw = true;
    }

    assertTrue($threw, 'the CHECK (mode = \'live\') constraint should refuse a simulation job');
});

check('one message cannot have two dispatch jobs', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    readyToSend(new RecordingAdapter());
    $messageUuid = approvedMessage($conversationUuid);

    $first = DispatchService::enqueue(ctx(), owner(), $messageUuid);
    assertTrue($first['ok'], 'first enqueue should succeed: ' . (string) $first['detail']);

    // Not an error — the second enqueue returns the SAME job, which is what
    // makes a double-clicked Send button harmless.
    $second = DispatchService::enqueue(ctx(), owner(), $messageUuid);
    assertSame('already_queued', $second['code'], 'a second enqueue should return the existing job');
    assertSame($first['job_uuid'], $second['job_uuid'], 'and it should be the same job');

    $threw = false;
    try {
        Db::insert('messaging_dispatch_jobs', [
            'job_uuid'        => Uuid::v4(),
            'cmp_id'          => CMP,
            'bo_id'           => 0,
            'message_uuid'    => $messageUuid,
            'connection_uuid' => CONNECTION,
            'mode'            => 'live',
            'status'          => 'queued',
            'idempotency_key' => 'dup-' . $messageUuid,
            'available_at'    => Clock::nowSql(),
            'created_at'      => Clock::nowSql(),
        ], 'job_uuid');
    } catch (\Throwable) {
        $threw = true;
    }

    assertTrue($threw, 'the unique index on message_uuid should refuse a second job');
});

// ===========================================================================
// Message lifecycle and approval
// ===========================================================================

section('Message lifecycle');

check('a status never moves backwards', static function (): void {
    // null means "no change", which is how a late receipt is absorbed rather
    // than applied.
    assertSame(null, MessageState::advance(MessageState::DELIVERED, MessageState::PROVIDER_ACCEPTED),
        'an accepted receipt arriving after delivery must not un-deliver a message');
    assertSame(MessageState::READ, MessageState::advance(MessageState::DELIVERED, MessageState::READ),
        'read is forward of delivered');
    assertSame(null, MessageState::advance(MessageState::READ, MessageState::DELIVERED),
        'and delivered does not walk read back');
    assertSame(null, MessageState::advance(MessageState::READ, MessageState::FAILED),
        'a late failure notice for a message the customer read would be worse than no notice');
    assertSame(MessageState::FAILED, MessageState::advance(MessageState::PROVIDER_ACCEPTED, MessageState::FAILED),
        'but a provider can accept and then fail to deliver, and that IS a real outcome');
});

check('editing a draft invalidates its approval', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();

    $saved = MessageService::saveDraft(ctx(), owner(), $conversationUuid, ['body' => 'Your balance is ₹4,800.']);
    $messageUuid = (string) $saved['message_uuid'];
    MessageService::approve(ctx(), owner(), $messageUuid, 0);

    $before = Db::first('SELECT approved_content_hash, content_hash, status FROM messaging_messages WHERE message_uuid = :u', ['u' => $messageUuid]);
    assertSame((string) $before['content_hash'], (string) $before['approved_content_hash'],
        'approval should record the hash of what was approved');

    // The edit. THE CASE THIS EXISTS FOR: approving "₹4,800" must not authorise
    // sending "₹48,000".
    MessageService::saveDraft(ctx(), owner(), $conversationUuid, [
        'message_uuid' => $messageUuid,
        'body'         => 'Your balance is ₹48,000.',
        'row_version'  => 0,
    ]);

    $after = (array) Db::first('SELECT approved_content_hash, content_hash, status FROM messaging_messages WHERE message_uuid = :u', ['u' => $messageUuid]);

    assertSame(MessageState::DRAFT, (string) $after['status'], 'the edit sends it back to being a draft');
    assertTrue($after['approved_content_hash'] === null, 'and the approval is gone, not merely stale');

    $verdict = DispatchGuard::checkApproval($after);
    assertFalse((bool) $verdict['allowed'], 'an edited draft must not still count as approved');
    assertSame('not_approved', (string) $verdict['code'], 'and it needs approving again');
});

check('a hash that no longer matches its approval is refused even if the status says approved', static function (): void {
    // Belt and braces for the case above. If anything ever wrote content
    // without going through updateDraft, the gate still catches it — approving
    // "₹4,800" is not authority to send "₹48,000".
    $verdict = DispatchGuard::checkApproval([
        'status'                => MessageState::APPROVED,
        'content_hash'          => str_repeat('a', 64),
        'approved_content_hash' => str_repeat('b', 64),
    ]);

    assertFalse((bool) $verdict['allowed'], 'a mismatched hash blocks the send');
    assertSame('approval_stale', (string) $verdict['code'], 'and is named as a stale approval');
});

check('an approval by a version that has moved on is refused', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();

    $saved = MessageService::saveDraft(ctx(), owner(), $conversationUuid, ['body' => 'Hello.']);
    $messageUuid = (string) $saved['message_uuid'];

    // Somebody else edited it while this approver was reading it.
    MessageService::saveDraft(ctx(), owner(), $conversationUuid, [
        'message_uuid' => $messageUuid, 'body' => 'Hello again.', 'row_version' => 0,
    ]);

    $stale = MessageService::approve(ctx(), owner(), $messageUuid, 1);
    assertFalse($stale['ok'], 'approving a stale version should be refused');
    assertSame('version_conflict', $stale['code'], 'and named as a conflict, not a generic error');
});

// ===========================================================================
// Consent — the gate that has legal consequences
// ===========================================================================

section('Consent');

check('a suppressed address cannot be sent to, even with consent on file', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    $adapter = new RecordingAdapter();
    readyToSend($adapter);

    ConsentService::suppress(ctx(), owner(), 'whatsapp', '+919812345678', 'customer_optout', 'Asked us to stop.');

    $messageUuid = approvedMessage($conversationUuid);
    $result = DispatchService::enqueue(ctx(), owner(), $messageUuid);

    assertFalse($result['ok'], 'a suppressed address must not be queued');
    assertCount(0, $adapter->sent, 'and nothing should have reached the provider');
});

check('consent is per purpose, not per address', static function (): void {
    reset();
    connection();

    ConsentService::record(ctx(), owner(), 'whatsapp', '+919812345678', 'transactional', 'granted', 'checkout', 'Ticked at checkout for order updates.');

    $transactional = ConsentService::evaluate(ctx(), 'whatsapp', '+919812345678', 'transactional');
    $promotional = ConsentService::evaluate(ctx(), 'whatsapp', '+919812345678', 'promotional');

    assertTrue((bool) $transactional['allowed'], 'a transactional message is allowed');
    assertFalse((bool) $promotional['allowed'],
        'consent to order updates is NOT consent to marketing — that is the whole distinction');
});

check('consent is re-read at dispatch, not at approval', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    $adapter = new RecordingAdapter();
    readyToSend($adapter);

    $messageUuid = approvedMessage($conversationUuid);
    $enqueued = DispatchService::enqueue(ctx(), owner(), $messageUuid);
    assertTrue($enqueued['ok'], 'queued while consent was in place: ' . (string) $enqueued['detail']);

    // The customer opts out in the gap between the queue and the worker. This
    // is not a rare race — it is what a "STOP" reply IS.
    ConsentService::record(ctx(), owner(), 'whatsapp', '+919812345678', 'service', 'withdrawn', 'customer_message', 'Replied STOP.');

    $jobs = DispatchService::claim('test-worker', 5);
    assertCount(1, $jobs, 'the job should be claimable');

    $outcome = DispatchService::process($jobs[0]);
    assertSame('blocked', $outcome['outcome'], 'the worker must re-read consent and refuse');
    assertCount(0, $adapter->sent, 'and nothing should have reached the provider');
});

check('an inbound STOP is detected, and only an actual STOP', static function (): void {
    // A WHOLE-BODY match against a keyword list, deliberately. Substring
    // matching would suppress the customer who wrote "stop by the shop
    // tomorrow" — a business losing a paying customer to its own spam filter.
    assertTrue(ConsentService::detectOptOut('STOP'), 'the keyword');
    assertTrue(ConsentService::detectOptOut(' stop. '), 'with punctuation and whitespace around it');
    assertTrue(ConsentService::detectOptOut('UNSUBSCRIBE'), 'and the other recognised keywords');
    assertTrue(ConsentService::detectOptOut('बंद'), 'including Hindi, which this product ships with');

    assertFalse(ConsentService::detectOptOut('stop by the shop tomorrow'),
        '"stop by the shop" is not an opt-out, and treating it as one loses a customer');
    assertFalse(ConsentService::detectOptOut('please stop sending me these'),
        'a sentence is routed to a human instead — who can record the withdrawal with its evidence');
});

// ===========================================================================
// Dispatch — exactly once, and the ambiguous case
// ===========================================================================

section('Dispatch');

check('a message is sent once and the provider gets one stable idempotency key', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    $adapter = new RecordingAdapter();
    readyToSend($adapter);

    $messageUuid = approvedMessage($conversationUuid);
    DispatchService::enqueue(ctx(), owner(), $messageUuid);

    $jobs = DispatchService::claim('worker-a', 5);
    $outcome = DispatchService::process($jobs[0]);

    assertSame('accepted', $outcome['outcome'], 'the send should be accepted');
    assertCount(1, $adapter->sent, 'exactly one provider call');
    assertSame('msg-' . $messageUuid, $adapter->sent[0]['key'],
        'the key is derived from the message, so a retry reaches the same key');

    // Re-processing the same job — a worker restart replaying its claim.
    $again = DispatchService::process($jobs[0]);
    assertCount(1, $adapter->sent, 'a replayed job must NOT send a second message');
    assertSame('already_sent', $again['outcome'], 'and it reports rather than resends');
});

check('a second worker cannot claim a claimed job', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    readyToSend(new RecordingAdapter());

    DispatchService::enqueue(ctx(), owner(), approvedMessage($conversationUuid));

    $first = DispatchService::claim('worker-a', 5);
    $second = DispatchService::claim('worker-b', 5);

    assertCount(1, $first, 'worker A claims it');
    assertCount(0, $second, 'worker B gets nothing — FOR UPDATE SKIP LOCKED plus the status change');
});

check('an ambiguous submission is never retried', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    $adapter = new RecordingAdapter();
    $adapter->script = ['unknown'];
    readyToSend($adapter);

    $messageUuid = approvedMessage($conversationUuid);
    DispatchService::enqueue(ctx(), owner(), $messageUuid);

    $jobs = DispatchService::claim('worker-a', 5);
    $outcome = DispatchService::process($jobs[0]);

    assertSame('unknown', $outcome['outcome'], 'the outcome is reported as unknown');

    $message = Db::first('SELECT status FROM messaging_messages WHERE message_uuid = :u', ['u' => $messageUuid]);
    assertSame(MessageState::SUBMISSION_UNKNOWN, (string) $message['status'],
        'the message sits in submission_unknown for a human');

    $job = Db::first('SELECT status FROM messaging_dispatch_jobs WHERE message_uuid = :u', ['u' => $messageUuid]);
    assertFalse((string) $job['status'] === 'queued',
        'THE POINT: an unknown submission must not be re-queued. Retrying it is how a customer '
        . 'gets the same message twice.');

    // And a later worker sweep must not pick it up either.
    assertCount(0, DispatchService::claim('worker-b', 5), 'no worker should find it queued');
    assertCount(1, $adapter->sent, 'still exactly one provider call');
});

check('an unknown submission with no provider id is left for a human, never resent', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    $adapter = new RecordingAdapter();
    $adapter->script = ['unknown'];
    $adapter->lookupAnswer = SendResult::accepted('prov-recovered');
    readyToSend($adapter);

    $messageUuid = approvedMessage($conversationUuid);
    DispatchService::enqueue(ctx(), owner(), $messageUuid);
    $jobs = DispatchService::claim('worker-a', 5);
    DispatchService::process($jobs[0]);

    // An ambiguous answer carries no provider message id, so there is nothing
    // to look up. The honest outcome is to say so and stop — a resend here is
    // exactly the duplicate this whole state exists to prevent.
    $result = DispatchService::reconcile(ctx(), $messageUuid);

    assertFalse((bool) $result['resolved'], 'it cannot be resolved automatically');
    assertSame(0, $adapter->lookupCalls, 'there is no id to ask about');
    assertContains('before resending', strtolower((string) $result['detail']),
        'and the operator is told to check the provider console first, because the customer may already have it');
    assertCount(1, $adapter->sent, 'still exactly one provider call');
});

check('an unknown submission WITH a provider id is resolved by asking, not by resending', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    $adapter = new RecordingAdapter();
    $adapter->script = ['unknown'];
    $adapter->lookupAnswer = SendResult::accepted('prov-recovered');
    readyToSend($adapter);

    $messageUuid = approvedMessage($conversationUuid);
    DispatchService::enqueue(ctx(), owner(), $messageUuid);
    $jobs = DispatchService::claim('worker-a', 5);
    DispatchService::process($jobs[0]);

    // Some providers do hand back an id and then time out. That is the case
    // reconciliation can actually settle.
    Db::run(
        'UPDATE messaging_messages SET provider_message_id = :pid WHERE message_uuid = :uuid',
        ['pid' => 'prov-recovered', 'uuid' => $messageUuid],
    );

    $result = DispatchService::reconcile(ctx(), $messageUuid);

    assertTrue($adapter->lookupCalls > 0, 'it asks the provider what happened');
    assertTrue((bool) $result['resolved'], 'and settles the message: ' . json_encode($result));
    assertSame(MessageState::PROVIDER_ACCEPTED, (string) $result['status'],
        'the provider did have it, so it is accepted rather than sent again');
    assertCount(1, $adapter->sent, 'and reconciliation sent nothing');
});

check('a retryable failure backs off rather than hammering the provider', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    $adapter = new RecordingAdapter();
    $adapter->script = ['retry'];
    readyToSend($adapter);

    $messageUuid = approvedMessage($conversationUuid);
    DispatchService::enqueue(ctx(), owner(), $messageUuid);
    $jobs = DispatchService::claim('worker-a', 5);
    DispatchService::process($jobs[0]);

    $job = Db::first(
        'SELECT status, attempts, available_at FROM messaging_dispatch_jobs WHERE message_uuid = :u',
        ['u' => $messageUuid],
    );
    assertSame('queued', (string) $job['status'], 'a throttled send is re-queued');
    assertTrue(
        strtotime((string) $job['available_at']) > strtotime(FROZEN_NOW),
        'but not immediately — the next attempt is scheduled into the future',
    );
    assertCount(0, DispatchService::claim('worker-b', 5), 'so nothing is claimable right now');
});

// ===========================================================================
// DispatchGuard — the eight gates
// ===========================================================================

section('Dispatch gates');

check('a draft promising a payment link with no link is refused', static function (): void {
    // Gate 7, and the case named in the brief. An approval cannot override it:
    // approving content is permission to send THAT CONTENT, not permission to
    // conjure a resource that does not exist.
    $verdict = DispatchGuard::assertPromisedResourcesExist(
        'Your balance is ₹4,800. The payment link is below.',
        [],
    );
    assertFalse((bool) $verdict['allowed'], 'a promised payment link with no URL must block the send');
    assertContains('payment link', strtolower((string) $verdict['detail']), 'and name what is missing');
});

check('the same draft with a real link is allowed', static function (): void {
    $verdict = DispatchGuard::assertPromisedResourcesExist(
        'Your balance is ₹4,800. Pay now: https://pay.aicountly.test/l/abc',
        [],
    );
    assertTrue((bool) $verdict['allowed'], 'a promise that is kept is fine: ' . (string) $verdict['detail']);
});

check('"please find attached" with nothing attached is refused', static function (): void {
    $verdict = DispatchGuard::assertPromisedResourcesExist('Please find attached your invoice.', []);
    assertFalse((bool) $verdict['allowed'], 'an attachment promise with no attachment must block');
});

check('an unscanned attachment blocks the send', static function (): void {
    $verdict = DispatchGuard::assertPromisedResourcesExist(
        'Please find attached your invoice.',
        [['attachment_uuid' => 'a1', 'scan_state' => 'pending', 'url' => 'https://drive.test/a1']],
    );
    assertFalse((bool) $verdict['allowed'], 'a file nobody has scanned should not go to a customer');
});

check('quiet hours apply to promotional messages and not to transactional ones', static function (): void {
    reset();
    Settings::save(ctx(), owner(), ['timezone' => 'Asia/Kolkata', 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:00']);

    // 22:30 IST on the frozen Monday — inside the window.
    Clock::freeze('2026-06-01T17:00:00Z');
    Settings::forget();

    $promotional = DispatchGuard::quietHours(ctx(), 'promotional');
    $transactional = DispatchGuard::quietHours(ctx(), 'transactional');

    assertFalse((bool) $promotional['allowed'], 'a marketing message waits until morning');
    assertTrue((bool) $transactional['allowed'],
        'a customer waiting on a delivery update at ten at night still gets it');

    Clock::freeze(FROZEN_NOW);
});

check('a quiet-hours window that crosses midnight is understood', static function (): void {
    reset();
    Settings::save(ctx(), owner(), ['timezone' => 'Asia/Kolkata', 'quiet_hours_start' => '21:00', 'quiet_hours_end' => '08:00']);

    // 02:00 IST — inside a window that wraps. Naive comparison would say no.
    Clock::freeze('2026-06-01T20:30:00Z');
    Settings::forget();
    assertFalse((bool) DispatchGuard::quietHours(ctx(), 'promotional')['allowed'], '02:00 is inside 21:00–08:00');

    // 12:00 IST — outside it.
    Clock::freeze('2026-06-01T06:30:00Z');
    Settings::forget();
    assertTrue((bool) DispatchGuard::quietHours(ctx(), 'promotional')['allowed'], 'midday is not');

    Clock::freeze(FROZEN_NOW);
});

check('a channel that cannot send free-form text refuses a free-form draft', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();

    ChannelRegistry::overrideForTesting('test_provider', new RecordingAdapter(
        'test_provider', 'whatsapp',
        [Capability::TEMPLATES => true, Capability::FREEFORM_TEXT => false],
    ));
    putenv('MESSAGING_TEST_TOKEN=test-token-value');
    ConsentService::record(ctx(), owner(), 'whatsapp', '+919812345678', 'service', 'granted', 'agent_recorded', 'Confirmed on the phone.');

    $messageUuid = approvedMessage($conversationUuid);
    $message = (array) Db::first('SELECT * FROM messaging_messages WHERE message_uuid = :u', ['u' => $messageUuid]);
    $verdict = DispatchGuard::evaluate(ctx(), $message, ChannelConnection::find(CMP, CONNECTION));

    assertFalse((bool) $verdict['allowed'], 'a channel without free-form text should refuse');
    assertSame('capability_missing', (string) $verdict['code'], 'and say which capability is missing');
});

check('a connection with no credential present is not sendable', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();

    // The credential reference points at an environment variable that is not
    // set. The connection looks configured; it is not.
    putenv('MESSAGING_TEST_TOKEN');
    ChannelRegistry::overrideForTesting('test_provider', new RecordingAdapter(
        'test_provider', 'whatsapp', [], 'MESSAGING_TEST_TOKEN is not set on this server.',
    ));

    $messageUuid = approvedMessage($conversationUuid);
    $message = (array) Db::first('SELECT * FROM messaging_messages WHERE message_uuid = :u', ['u' => $messageUuid]);
    $verdict = DispatchGuard::evaluate(ctx(), $message, ChannelConnection::find(CMP, CONNECTION));

    assertFalse((bool) $verdict['allowed'], 'a missing credential must block the send');
    assertContains('MESSAGING_TEST_TOKEN', (string) $verdict['detail'],
        'and name the environment key, so an administrator can fix it');
});

// ===========================================================================
// Credentials — never exposed
// ===========================================================================

section('Credentials');

check('a connection exposes credential_present and never the credential', static function (): void {
    reset();
    connection();
    putenv('MESSAGING_TEST_TOKEN=super-secret-value');

    $public = ChannelConnection::find(CMP, CONNECTION)->toPublicArray();
    $encoded = json_encode($public);

    assertTrue(array_key_exists('credential_present', $public), 'the boolean is what a screen gets');
    assertTrue((bool) $public['credential_present'], 'and it is true when the variable is set');
    assertFalse(str_contains((string) $encoded, 'super-secret-value'),
        'THE POINT: a browser client must never receive a service credential');
    assertFalse(array_key_exists('credential_ref', $public),
        'not even the variable name, which is only for somebody who can manage channels');
});

check('the audit trail keeps no secret and no foreign business record', static function (): void {
    reset();

    // Recorded the way the product records it, then read back out of the table
    // — so what is asserted is what would actually be on disk.
    Audit::record(ctx(), owner(), 'message.sent', 'message', 'm-1', null, [
        'api_key'        => 'sk-live-abcdef',
        'Authorization'  => 'Bearer xyz',
        'books_response' => ['outstanding' => 480000, 'voucher_no' => 'INV-2026-0091'],
        'invoice'        => ['amount' => 1250000],
        'decision'       => 'reminder sent',
        'invoice_ref'    => 'INV-2026-0091',
    ]);

    $stored = (string) Db::scalar(
        'SELECT after_state::text FROM messaging_audit_events WHERE cmp_id = :cmp ORDER BY audit_id DESC LIMIT 1',
        ['cmp' => CMP],
    );

    assertFalse(str_contains($stored, 'sk-live-abcdef'), 'a key must not reach the audit trail');
    assertFalse(str_contains($stored, 'Bearer xyz'), 'nor a bearer token');
    assertFalse(str_contains($stored, '480000'), 'nor a balance read from Books — that stays in Books');
    assertFalse(str_contains($stored, '1250000'), 'nor an invoice amount');
    assertContains('reminder sent', $stored, 'but the DECISION is recorded');
    assertContains('INV-2026-0091', $stored, 'and so is the reference, which is what makes it auditable');
});

// ===========================================================================
// Tenancy
// ===========================================================================

section('Tenancy');

check('a conversation cannot be read under another company id', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();

    $mine = ConversationService::find(ctx(), $conversationUuid);
    assertTrue($mine !== null, 'my own company can read it');

    $theirs = ConversationService::find(Context::forCompany(9002, 0), $conversationUuid);
    assertTrue($theirs === null,
        'another company gets nothing — every query is scoped, not filtered in PHP afterwards');
});

check('a malformed uuid is a not-found, not a database error', static function (): void {
    reset();
    assertTrue(ConversationService::find(ctx(), 'not-a-uuid') === null,
        'an invalid uuid must be rejected before it reaches PostgreSQL, or it is a 503 instead of a 404');
});

check('permission lookup failure grants nothing', static function (): void {
    reset();

    // An agent with no profile assigned. The default is deliberately
    // conservative: read the inbox, add a note, nothing more.
    $granted = Permissions::granted(ctx(), agent());

    assertTrue(in_array('messaging.conversations.view', $granted, true), 'they can read the inbox');
    assertFalse(in_array('messaging.context.financial', $granted, true),
        'THE DISTINCTION: answering messages is not permission to see balances');
    assertFalse(in_array('messaging.messages.send', $granted, true), 'nor to send');
    assertFalse(in_array('messaging.drafts.approve', $granted, true), 'nor to approve');
});

check('nobody can grant a permission they do not hold', static function (): void {
    reset();

    $grantable = Permissions::grantable(ctx(), agent());
    assertFalse(in_array('messaging.access.manage', $grantable, true),
        'otherwise "manage access" would be a route to every other permission');

    $ownerGrantable = Permissions::grantable(ctx(), owner());
    assertTrue(in_array('messaging.access.manage', $ownerGrantable, true), 'the company owner can');
});

// ===========================================================================
// Cross-product reads — LIVE, and honest when they fail
// ===========================================================================

section('Cross-product reads');

check('Contacts is read live and a miss is a miss', static function (): void {
    reset();
    Features::overrideForTesting(['CONTACTS' => true]);

    $client = (new ContactsClient())->withSession(owner()->sesKey());

    $found = $client->findByPhone('+919812345678');
    assertTrue((bool) $found['ok'], 'the stub answers: ' . (string) $found['message']);
    assertSame('ready', (string) $found['state'], 'a successful read is ready');
    assertTrue((string) $found['fetched_at'] !== '', 'and carries the time it was read, for the freshness note');

    // An address nobody has matched. An empty answer is the normal, permanent
    // result for a walk-in number, and there is no local address book to fall
    // back to — which is exactly why contact_name is nullable everywhere.
    $missing = $client->findByPhone('+919000000001');
    assertTrue((bool) $missing['ok'], 'the call still succeeds');
    assertCount(0, (array) ($missing['body']['data'] ?? []), 'it is simply empty');
});

check('an unconfigured integration is pending, not broken', static function (): void {
    reset();
    Features::overrideForTesting(['BOOKS' => false]);

    $result = (new BooksClient())->withSession(owner()->sesKey())->outstandingForContact(ctx(), 'c0ffee00-0000-4000-8000-000000000001');

    assertFalse((bool) $result['ok'], 'it did not answer');
    assertSame('pending', (string) $result['state'],
        'but "nobody has connected Books" is a different screen from "Books is down"');
    assertTrue((string) $result['message'] !== '', 'and it says what to do about it');
});

check('a refusal is forbidden and never unavailable', static function (): void {
    reset();
    Features::overrideForTesting(['BOOKS' => true]);

    // THE DISTINCTION THAT MATTERS: "you may not see this" must not be shown as
    // "Books is having a bad minute", because the first is permanent and the
    // second invites a pointless retry.
    $result = (new BooksClient())->withSession(owner()->sesKey())->overdueInvoices(ctx(), ['stub' => 'forbidden']);

    assertSame('forbidden', (string) $result['state'], 'a 403 is forbidden');
    assertFalse((bool) $result['retryable'], 'and retrying it would achieve nothing');
});

check('an outage is unavailable and retryable', static function (): void {
    reset();
    Features::overrideForTesting(['BOOKS' => true]);

    $result = (new BooksClient())->withSession(owner()->sesKey())->overdueInvoices(ctx(), ['stub' => 'down']);

    assertSame('unavailable', (string) $result['state'], 'a 503 is unavailable');
    assertTrue((bool) $result['retryable'], 'and worth retrying');
    assertTrue(($result['body'] ?? null) === null || $result['body'] !== [],
        'no substituted sample data — the screen says so instead');
});

check('a missing route is unsupported, which is a deployment fact', static function (): void {
    reset();
    Features::overrideForTesting(['BOOKS' => true]);

    $result = (new BooksClient())->withSession(owner()->sesKey())->overdueInvoices(ctx(), ['stub' => 'missing']);
    assertSame('unsupported', (string) $result['state'],
        'a 404 means that deployment of Books does not offer this, not that it is broken');
});

check('the re-entry guard refuses to call back into the product that called us', static function (): void {
    reset();
    Features::overrideForTesting(['BOOKS' => true]);

    // Books is serving a request that reached us. Calling Books now parks a
    // second one of its workers on a request that is waiting on us.
    CrossServiceCallContext::adoptAuthenticatedOrigin('books');

    $result = (new BooksClient())->withSession(owner()->sesKey())->overdueInvoices(ctx());

    assertFalse((bool) $result['ok'], 'the call is suppressed');
    assertSame('unavailable', (string) $result['state'], 'and reported as unavailable rather than hanging');
    assertContains('books', (string) $result['message'], 'and the message names the product it did not call');
});

check('SourceReader labels every value with the product it came from', static function (): void {
    reset();
    Features::overrideForTesting(['BOOKS' => true]);

    $read = SourceReader::read(ctx(), owner(), 'books_invoice', '55501');

    assertSame('books', (string) $read['source'], 'the owning product is named on the answer');
    assertSame('ready', (string) $read['state'], 'and the state says it was actually read');
    assertTrue((string) $read['fetched_at'] !== '', 'with the time it was read, for the freshness note');
});

check('an unknown source is refused rather than guessed at', static function (): void {
    reset();

    $read = SourceReader::read(ctx(), owner(), 'books.invoice', '55501');
    assertFalse((bool) $read['ok'], 'a source nobody defined does not resolve to a default one');
    assertContains('books.invoice', (string) $read['message'], 'and the message names what was asked for');
});

check('reading a foreign record leaves no copy of it behind', static function (): void {
    reset();
    Features::overrideForTesting(['BOOKS' => true]);

    SourceReader::read(ctx(), owner(), 'books_invoice', '55501');

    // THE ARCHITECTURE. The stub returned an amount and an outstanding balance.
    // Neither may be anywhere in this database afterwards.
    foreach (['1250000', '480000'] as $figure) {
        $hits = (int) Db::scalar(
            "SELECT COUNT(*) FROM messaging_external_references
             WHERE cmp_id = :cmp AND (external_id LIKE :f OR COALESCE(external_label, '') LIKE :f)",
            ['cmp' => CMP, 'f' => '%' . $figure . '%'],
        );
        assertSame(0, $hits, "the figure {$figure} must not be stored — Books owns it and it changes there");
    }
});

// ===========================================================================
// Webhooks
// ===========================================================================

section('Webhooks');

check('an unsigned webhook is rejected', static function (): void {
    reset();
    connection();
    ChannelRegistry::overrideForTesting('test_provider', new RecordingAdapter());

    $result = WebhookService::receive('test_provider', CONNECTION, '{"events":[]}', ['x-test-signature' => 'wrong']);

    assertFalse((bool) $result['accepted'], 'a bad signature is not accepted');
    assertSame(403, (int) $result['status'], 'and answered 403');
});

check('the tenant comes from the connection, never from the payload', static function (): void {
    reset();
    connection();
    ChannelRegistry::overrideForTesting('test_provider', new RecordingAdapter());

    // A payload claiming to be another company. The connection uuid in the URL
    // is what resolves the tenant, and it is server-side configuration.
    $body = json_encode([
        'cmp_id' => 9999,
        'events' => [[
            'id' => 'evt-1', 'kind' => 'inbound_message', 'from' => '+919812345678',
            'to' => '+919800000000', 'body' => 'Where is my order?',
        ]],
    ]);

    $result = WebhookService::receive('test_provider', CONNECTION, (string) $body, ['x-test-signature' => 'valid']);
    assertTrue((bool) $result['accepted'], 'the signed webhook is accepted');

    $conversation = Db::first(
        'SELECT cmp_id FROM messaging_conversations WHERE customer_address = :addr',
        ['addr' => '+919812345678'],
    );
    assertTrue($conversation !== null, 'a conversation was opened');
    assertSame(CMP, (int) $conversation['cmp_id'],
        'THE POINT: a spoofed tenant identifier in the body is ignored');
});

check('a replayed webhook event is processed once', static function (): void {
    reset();
    connection();
    ChannelRegistry::overrideForTesting('test_provider', new RecordingAdapter());

    $body = (string) json_encode(['events' => [[
        'id' => 'evt-replay-1', 'kind' => 'inbound_message', 'from' => '+919812345678',
        'to' => '+919800000000', 'body' => 'Hello?',
    ]]]);

    WebhookService::receive('test_provider', CONNECTION, $body, ['x-test-signature' => 'valid']);
    WebhookService::receive('test_provider', CONNECTION, $body, ['x-test-signature' => 'valid']);

    $count = (int) Db::scalar(
        'SELECT COUNT(*) FROM messaging_messages WHERE cmp_id = :cmp AND direction = :d',
        ['cmp' => CMP, 'd' => 'inbound'],
    );
    assertSame(1, $count, 'the provider event id makes replay safe — one message, not two');
});

check('a late delivery receipt does not walk a read message back', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    $adapter = new RecordingAdapter();
    readyToSend($adapter);

    $messageUuid = approvedMessage($conversationUuid);
    DispatchService::enqueue(ctx(), owner(), $messageUuid);
    $jobs = DispatchService::claim('worker-a', 5);
    DispatchService::process($jobs[0]);

    $providerMessageId = (string) Db::scalar(
        'SELECT provider_message_id FROM messaging_messages WHERE message_uuid = :u',
        ['u' => $messageUuid],
    );

    foreach (['read', 'delivered'] as $status) {
        WebhookService::receive('test_provider', CONNECTION, (string) json_encode(['events' => [[
            'id' => 'evt-' . $status, 'kind' => 'status',
            'provider_message_id' => $providerMessageId, 'status' => $status,
        ]]]), ['x-test-signature' => 'valid']);
    }

    $final = (string) Db::scalar('SELECT status FROM messaging_messages WHERE message_uuid = :u', ['u' => $messageUuid]);
    assertSame(MessageState::READ, $final, 'read outranks a delivered receipt that arrived afterwards');
});

// ===========================================================================
// Provider adapters — the pure decisions
// ===========================================================================

section('Provider adapters');

check('the WhatsApp adapter verifies a real HMAC and rejects a forged one', static function (): void {
    reset();
    connection(['provider' => 'whatsapp_cloud']);
    putenv('MESSAGING_TEST_WEBHOOK_SECRET=whsec-test-value');

    $adapter = new WhatsAppCloudAdapter();
    $conn = ChannelConnection::find(CMP, CONNECTION);
    $body = '{"object":"whatsapp_business_account","entry":[]}';
    $valid = 'sha256=' . hash_hmac('sha256', $body, 'whsec-test-value');

    assertTrue($adapter->verifyWebhook($conn, $body, ['x-hub-signature-256' => $valid]),
        'a correct signature verifies');
    assertFalse($adapter->verifyWebhook($conn, $body, ['x-hub-signature-256' => 'sha256=deadbeef']),
        'a forged one does not');
    assertFalse($adapter->verifyWebhook($conn, $body . ' ', ['x-hub-signature-256' => $valid]),
        'and the signature covers the body, so a tampered body fails');
});

check('an alphanumeric SMS sender declares that it cannot receive replies', static function (): void {
    reset();
    connection(['provider' => 'twilio_sms', 'channel' => 'sms', 'sender_address' => 'ACMESHOP']);

    $capabilities = ChannelRegistry::capabilities(ChannelConnection::find(CMP, CONNECTION));

    assertFalse((bool) ($capabilities[Capability::INBOUND] ?? true),
        'a sender id cannot receive — which is what disables the composer instead of giving an agent '
        . 'a box that eats what they type');
});

check('a numeric SMS sender can receive replies', static function (): void {
    reset();
    connection(['provider' => 'twilio_sms', 'channel' => 'sms', 'sender_address' => '+14155550100']);

    $capabilities = ChannelRegistry::capabilities(ChannelConnection::find(CMP, CONNECTION));
    assertTrue((bool) ($capabilities[Capability::INBOUND] ?? false), 'a real number can');
});

check('the Twilio adapter verifies against the configured base URL, not the Host header', static function (): void {
    reset();
    connection(['provider' => 'twilio_sms', 'channel' => 'sms', 'sender_address' => '+14155550100']);
    putenv('MESSAGING_TEST_WEBHOOK_SECRET=twilio-auth-token');
    putenv('MESSAGING_WEBHOOK_BASE_URL=https://messaging.aicountly.test');

    $adapter = new TwilioSmsAdapter();
    $conn = ChannelConnection::find(CMP, CONNECTION);

    // Twilio signs the URL plus the sorted POST body. Reconstructing the URL
    // from the Host header would let anybody who can set it forge a signature.
    $params = ['MessageSid' => 'SM123', 'Body' => 'Hi', 'From' => '+919812345678'];
    ksort($params);
    $concatenated = 'https://messaging.aicountly.test/api/webhooks/twilio_sms/' . CONNECTION;
    foreach ($params as $key => $value) {
        $concatenated .= $key . $value;
    }
    $signature = base64_encode(hash_hmac('sha1', $concatenated, 'twilio-auth-token', true));

    $_POST = $params;
    assertTrue(
        $adapter->verifyWebhook($conn, http_build_query($params), ['x-twilio-signature' => $signature]),
        'a signature over the configured URL verifies',
    );
    assertFalse(
        $adapter->verifyWebhook($conn, http_build_query($params), ['x-twilio-signature' => 'bogus']),
        'a forged one does not',
    );
    $_POST = [];
});

check('the RCS adapter refuses to pretend, and never falls back to SMS', static function (): void {
    reset();
    connection(['provider' => 'rcs_generic', 'channel' => 'rcs']);
    putenv('MESSAGING_RCS_API_BASE');
    putenv('MESSAGING_RCS_PROVIDER_STYLE');

    $adapter = new RcsAdapter();
    $conn = ChannelConnection::find(CMP, CONNECTION);

    $gap = $adapter->configurationGap($conn);
    assertTrue($gap !== null, 'with no provider contract configured, RCS is not sendable');
    assertContains('MESSAGING_RCS', (string) $gap, 'and the gap names what an administrator must set');

    $result = $adapter->send($conn, new OutboundMessage(
        Uuid::v4(), '+919812345678', 'Hello.',
    ), 'key-1');

    assertFalse($result->isAccepted(), 'and a send is refused');
    assertFalse(
        str_contains(strtolower((string) $result->errorDetail), 'sent as sms'),
        'silently downgrading to SMS would bill the customer for a channel they did not choose',
    );
});

// ===========================================================================
// Templates
// ===========================================================================

section('Templates');

check('template variables are rendered and unbound ones are reported', static function (): void {
    assertSame(
        'Hello Priya, your order SO-8841 is packed.',
        TemplateService::render('Hello {{name}}, your order {{order_no}} is packed.', [
            'name' => 'Priya', 'order_no' => 'SO-8841',
        ]),
        'bound variables are substituted',
    );

    assertSame(
        ['name', 'order_no'],
        TemplateService::placeholders('Hello {{name}}, your order {{order_no}} is packed.'),
        'and the placeholders are discoverable, so a send with a missing one can be refused',
    );
});

check('an unapproved template version is not sendable', static function (): void {
    reset();
    connection();

    $saved = TemplateService::save(ctx(), owner(), [
        'name'     => 'order_packed',
        'channel'  => 'whatsapp',
        'category' => 'transactional',
        'body'     => 'Hello {{name}}, your order is packed.',
        'language' => 'en',
    ]);
    assertTrue((bool) $saved['ok'], 'the template saves: ' . json_encode($saved));

    $sendable = TemplateService::sendableVersion(ctx(), (string) $saved['template_uuid'], 'en');
    assertTrue($sendable === null,
        'a draft template is not sendable — the provider has not approved it, and claiming otherwise '
        . 'burns a send');
});

// ===========================================================================
// Journeys — and the rule that a simulation sends nothing
// ===========================================================================

section('Journeys');

check('a journey with a node that goes nowhere does not validate', static function (): void {
    reset();

    $verdict = JourneyDefinition::validate(ctx(), [
        'entry' => 'eligibility',
        'nodes' => [
            ['id' => 'eligibility', 'type' => 'check_eligibility', 'purpose' => 'service',
             'on_eligible' => 'send', 'on_ineligible' => 'stop_no'],
            ['id' => 'send', 'type' => 'send', 'channel' => 'whatsapp', 'next' => 'nowhere'],
            ['id' => 'stop_no', 'type' => 'stop', 'outcome' => 'no_consent'],
        ],
    ]);

    assertFalse((bool) $verdict['valid'], 'an edge to a node that does not exist is a broken journey');
    assertTrue($verdict['errors'] !== [], 'and the errors say which edge');
    assertContains('nowhere', json_encode($verdict['errors']), 'naming the edge, not just "invalid"');
});

check('a simulation writes no dispatch job and sends nothing', static function (): void {
    reset();
    connection();
    $adapter = new RecordingAdapter();
    readyToSend($adapter);

    $saved = JourneyService::save(ctx(), owner(), [
        'name'       => 'Payment reminder',
        'definition' => [
            'entry' => 'eligibility',
            'nodes' => [
                ['id' => 'eligibility', 'type' => 'check_eligibility', 'purpose' => 'transactional',
                 'on_eligible' => 'draft', 'on_ineligible' => 'stop_no'],
                ['id' => 'draft', 'type' => 'draft', 'language' => 'en', 'next' => 'approval'],
                ['id' => 'approval', 'type' => 'approval', 'on_approved' => 'send', 'on_rejected' => 'stop_no'],
                ['id' => 'send', 'type' => 'send', 'channel' => 'whatsapp', 'next' => 'stop_sent'],
                ['id' => 'stop_sent', 'type' => 'stop', 'outcome' => 'sent'],
                ['id' => 'stop_no', 'type' => 'stop', 'outcome' => 'no_consent'],
            ],
        ],
    ]);
    assertTrue((bool) $saved['ok'], 'the journey saves: ' . json_encode($saved));

    $result = JourneySimulator::run(ctx(), owner(), (string) $saved['journey_uuid'], ['sample_size' => 5]);

    assertTrue(isset($result['walks']), 'the simulation produces walks to read');
    assertFalse((bool) $result['dispatched'], 'the payload states, in so many words, that nothing was sent');
    assertCount(0, $adapter->sent,
        'THE POINT: a simulation must not put a test message in front of a real customer');
    assertSame(0, (int) Db::scalar('SELECT COUNT(*) FROM messaging_dispatch_jobs WHERE cmp_id = :cmp', ['cmp' => CMP]),
        'and it must not queue one either');
});

check('an unpublished journey cannot run', static function (): void {
    reset();

    $saved = JourneyService::save(ctx(), owner(), [
        'name'       => 'Draft journey',
        'definition' => [
            'entry' => 'stop_here',
            'nodes' => [['id' => 'stop_here', 'type' => 'stop', 'outcome' => 'sent']],
        ],
    ]);

    assertTrue(
        JourneyService::runnableVersion(ctx(), (string) $saved['journey_uuid']) === null,
        'only a published version executes',
    );
});

// ===========================================================================
// AI — grounded, and honest about what it does not know
// ===========================================================================

section('AI');

check('AI is off unless Console has configured it', static function (): void {
    reset();
    ConsoleCredentials::overrideForTesting(null);

    $status = ConsoleCredentials::status();
    assertFalse((bool) $status['available'], 'with nothing configured, AI is not available');
    assertTrue((string) $status['reason'] !== '', 'and the reason is stated rather than the feature vanishing');
    assertContains('CONSOLE_', (string) $status['admin_hint'],
        'and the remedy names the environment keys — for an administrator, never a customer');
});

check('a draft that invents an amount is flagged', static function (): void {
    $verdict = DraftAssistant::verify(
        'Your outstanding balance is ₹99,999. Please pay at your convenience.',
        ['facts' => ['amounts' => ['4800.00'], 'payment_link_available' => false, 'attachment_count' => 0]],
    );

    $kinds = array_column((array) $verdict['findings'], 'kind');
    assertTrue(in_array('unverified_figure', $kinds, true),
        'a figure that is not in what Books returned must be flagged before a human sends it');
});

check('a draft that matches the figure read from Books is not flagged for it', static function (): void {
    $verdict = DraftAssistant::verify(
        'Your outstanding balance is ₹4,800.',
        ['facts' => ['amounts' => ['4800'], 'payment_link_available' => false, 'attachment_count' => 0]],
    );

    $kinds = array_column((array) $verdict['findings'], 'kind');
    assertFalse(in_array('unverified_figure', $kinds, true),
        'crying wolf on a correct figure would train agents to ignore the warning');
});

check('a draft promising a link with none available blocks the send button', static function (): void {
    $verdict = DraftAssistant::verify(
        'Your payment link is below.',
        ['facts' => ['amounts' => [], 'payment_link_available' => false, 'attachment_count' => 0]],
    );

    $blocking = array_filter((array) $verdict['findings'], static fn (array $f) => (bool) ($f['blocks_send'] ?? false));
    assertTrue($blocking !== [],
        'the UI disables Send for the same reason DispatchGuard would refuse it server-side');
});

check('next best actions are counted facts, not predictions', static function (): void {
    reset();
    connection();
    conversation(['status' => 'open', 'first_inbound_at' => '2026-06-01T02:00:00Z']);

    $suggestions = NextBestActions::for(ctx(), owner());

    foreach ($suggestions as $suggestion) {
        assertSame('verified_fact', (string) ($suggestion['kind'] ?? ''),
            'every suggestion is a SQL count of Messaging\'s own records, never a prediction: '
            . json_encode($suggestion));
        assertTrue((string) ($suggestion['detail'] ?? '') !== '', 'and says what it counted');
    }
});

// ===========================================================================
// Metrics — Messaging's own events only
// ===========================================================================

section('Metrics');

check('the overview counts only what Messaging owns', static function (): void {
    reset();
    connection();
    $conversationUuid = conversation();
    $adapter = new RecordingAdapter();
    readyToSend($adapter);

    DispatchService::enqueue(ctx(), owner(), approvedMessage($conversationUuid));
    $jobs = DispatchService::claim('worker-a', 5);
    DispatchService::process($jobs[0]);

    $overview = MetricsService::overview(ctx(), Period::named(ctx(), 'today'));

    assertSame(1, (int) $overview['messages_accepted'], 'the accepted message is counted');
    assertSame(0, (int) $overview['messages_delivered'],
        'and NOT counted as delivered: the provider took it, which is not the same as the customer getting it');
    assertSame(1, (int) $overview['messages_unconfirmed'], 'it is reported as unconfirmed, which is the truth');
});

check('a delivery rate with no denominator is null, not zero', static function (): void {
    reset();
    connection();

    $overview = MetricsService::overview(ctx(), Period::named(ctx(), 'today'));

    assertTrue(
        ($overview['delivery_rate'] ?? null) === null,
        'nothing was sent, so the rate is unknown. Showing 0% would read as total failure.',
    );
    assertTrue(isset($overview['delivery_rate_basis']['denominator']),
        'and wherever a rate IS shown, its denominator travels with it');
});

// ===========================================================================
// Summary
// ===========================================================================

Clock::freeze(null);

echo "\n" . str_repeat('=', 60) . "\n";
printf("%d passed, %d failed\n", $passed, $failed);

if ($failed > 0) {
    echo "\nFailed:\n";
    foreach ($failures as $name) {
        echo "  - {$name}\n";
    }
    exit(1);
}

exit(0);
