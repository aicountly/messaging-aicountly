<?php

declare(strict_types=1);

/**
 * Usage metering: what Messaging reports to Manage's central usage meter
 * (manage-aicountly docs/usage-metering/).
 *
 *   server-php/tests/run.sh
 *
 * Against the same test database as integration.php, with its own company. No
 * provider is reached: the real adapters' metering decisions are pure functions
 * over a provider answer (as their other decisions are tested), and the send
 * pipeline and the webhook pipeline run for real against RecordingAdapter and
 * WebhookService. The meter spools to a temporary directory and its transport
 * is a fake, so nothing is sent to Manage either.
 *
 * What these prove:
 *   - one event per provider operation, keyed on the provider's own id, and the
 *     same key (so the same event id) when the same operation is seen again;
 *   - attribution from the stored message — the company, and the product whose
 *     service key asked for the send — never from a request;
 *   - no recipient, body or raw WhatsApp message id in anything spooled;
 *   - a send's outcome is the same with metering off or broken.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';
require __DIR__ . '/../src/Metering/AicountlyUsageMeter.php';
require __DIR__ . '/support/RecordingAdapter.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Channels\ChannelConnection;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Channels\RcsAdapter;
use Aicountly\Api\Channels\SendResult;
use Aicountly\Api\Channels\TwilioSmsAdapter;
use Aicountly\Api\Channels\WhatsAppCloudAdapter;
use Aicountly\Api\Domain\ConsentService;
use Aicountly\Api\Domain\DispatchService;
use Aicountly\Api\Domain\MessageService;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Domain\WebhookService;
use Aicountly\Api\Metering\MessagingMeter;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;
use Aicountly\Api\Tests\RecordingAdapter;
use Aicountly\Metering\UsageMeter;

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
        UsageMeter::setShared(null);
        ChannelRegistry::overrideForTesting('test_provider', null);
    }
}

function assertSame(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf('%s: expected %s, got %s', $what, var_export($expected, true), var_export($actual, true)));
    }
}

function assertTrue(bool $condition, string $what): void
{
    if (!$condition) {
        throw new \RuntimeException($what);
    }
}

const CMP = 9401;
const CONNECTION = '44444444-4444-4444-8444-444444444444';
const FROZEN_NOW = '2026-06-01T04:00:00Z';   // 09:30 Asia/Kolkata, a Monday: outside quiet hours
const CUSTOMER = '+919811122233';
const BODY = 'Your order SO-8841 is on its way.';

function ctx(): Context
{
    return Context::forCompany(CMP);
}

function owner(): Auth
{
    return Auth::forTesting('user-owner', 'user', 'messaging');
}

/** A meter that spools into a fresh directory and can reach nobody. */
function meterInto(?string $dir = null, string $enabled = '1', string $url = 'http://127.0.0.1:9/api'): string
{
    $dir ??= (getenv('METERING_TEST_SPOOL') ?: sys_get_temp_dir()) . '/messaging-meter-' . bin2hex(random_bytes(4));
    UsageMeter::setShared(new UsageMeter([
        'enabled' => $enabled, 'flush' => 'cron', 'spool_dir' => $dir, 'source' => 'messaging',
        'environment' => 'development', 'url' => $url, 'key' => 'k',
    ], static fn (): array => [0, '']));

    return $dir;
}

/** @return list<array<string, mixed>> the spooled events, oldest first */
function spooled(string $dir): array
{
    $files = glob($dir . '/*.json') ?: [];
    sort($files);

    return array_map(static fn (string $f): array => json_decode((string) file_get_contents($f), true), $files);
}

/** Nothing that identifies the customer or says what they were sent. */
function assertNoContent(string $dir, string ...$extra): void
{
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $raw = (string) file_get_contents($file);
        foreach (['9811122233', 'SO-8841', 'on its way', ...$extra] as $needle) {
            assertTrue(!str_contains($raw, $needle), basename($file) . ' must not contain "' . $needle . '"');
        }
    }
}

/** This company's rows, so nothing is left for another suite that reads the same database. */
function cleanUp(): void
{
    foreach ([
        'messaging_delivery_events', 'messaging_webhook_receipts', 'messaging_dispatch_jobs',
        'messaging_approvals', 'messaging_message_attachments', 'messaging_messages',
        'messaging_conversations', 'messaging_consent_events', 'messaging_consent_records',
        'messaging_suppressions', 'messaging_daily_metrics', 'messaging_channel_capabilities',
        'messaging_channel_connections', 'messaging_audit_events', 'messaging_settings',
    ] as $table) {
        Db::run('DELETE FROM ' . $table . ' WHERE cmp_id = :cmp', ['cmp' => CMP]);
    }
}

function reset(): void
{
    cleanUp();
    Settings::forget();
    Permissions::forget();
    Clock::freeze(FROZEN_NOW);
    Context::resetForTesting();
    Context::trustForTesting(CMP, owner(), true, [['fy_id' => 7, 'fy_start' => '2026-04-01', 'fy_end' => '2027-03-31']]);

    Db::insert('messaging_channel_connections', [
        'connection_uuid' => CONNECTION, 'cmp_id' => CMP, 'bo_id' => 0, 'channel' => 'whatsapp',
        'provider' => 'test_provider', 'display_name' => 'Metering line', 'sender_address' => '+919800000000',
        'provider_account_ref' => 'acct-1', 'credential_ref' => 'MESSAGING_TEST_TOKEN',
        'webhook_secret_ref' => 'MESSAGING_TEST_WEBHOOK_SECRET', 'status' => 'connected', 'is_active' => true,
        'created_at' => Clock::nowSql(), 'updated_at' => Clock::nowSql(),
    ], 'connection_uuid');
    putenv('MESSAGING_TEST_TOKEN=test-token-value');
    ConsentService::record(ctx(), owner(), 'whatsapp', CUSTOMER, 'service', 'granted', 'agent_recorded', 'Confirmed on the phone.');
}

/**
 * Send one approved message through the real pipeline (enqueue, then the
 * worker's process()) with the adapter answering $answer.
 *
 * @return array{outcome:string, message:array<string, mixed>}
 */
function send(SendResult $answer, ?string $serviceApp = 'drive'): array
{
    // One open conversation per customer and line, as the schema insists.
    $conversation = Db::scalar(
        'SELECT conversation_uuid FROM messaging_conversations WHERE cmp_id = :cmp AND connection_uuid = :conn',
        ['cmp' => CMP, 'conn' => CONNECTION],
    );
    if ($conversation === null) {
        $conversation = Uuid::v4();
        Db::insert('messaging_conversations', [
            'conversation_uuid' => $conversation, 'cmp_id' => CMP, 'bo_id' => 0, 'connection_uuid' => CONNECTION,
            'channel' => 'whatsapp', 'customer_address' => CUSTOMER, 'status' => 'open',
            'first_inbound_at' => Clock::nowSql(), 'last_inbound_at' => Clock::nowSql(),
            'created_at' => Clock::nowSql(), 'updated_at' => Clock::nowSql(),
        ], 'conversation_uuid');
    }

    $saved = MessageService::saveDraft(ctx(), owner(), $conversation, ['body' => BODY]);
    assertTrue($saved['ok'], 'fixture: draft — ' . $saved['detail']);
    $uuid = (string) $saved['message_uuid'];
    assertTrue(MessageService::approve(ctx(), owner(), $uuid, 0)['ok'], 'fixture: approve');
    // What ServiceController writes from the calling product's service key.
    Db::run('UPDATE messaging_messages SET service_app = :app WHERE message_uuid = :uuid', ['app' => $serviceApp, 'uuid' => $uuid]);

    $adapter = new RecordingAdapter();
    $adapter->script = [$answer];
    ChannelRegistry::overrideForTesting('test_provider', $adapter);

    $queued = DispatchService::enqueue(ctx(), owner(), $uuid);
    assertTrue($queued['ok'], 'fixture: enqueue — ' . $queued['detail']);
    $job = Db::first('SELECT * FROM messaging_dispatch_jobs WHERE message_uuid = :uuid', ['uuid' => $uuid]);
    $outcome = DispatchService::process($job);

    return [
        'outcome' => $outcome['outcome'],
        'message' => Db::first('SELECT * FROM messaging_messages WHERE message_uuid = :uuid', ['uuid' => $uuid]),
    ];
}

/** A Twilio send answer, as its API returns one: the price is not known yet. */
function twilioAccepted(string $sid, array $overrides = []): SendResult
{
    $body = $overrides + [
        'sid' => $sid, 'status' => 'queued', 'num_segments' => '2', 'price' => null, 'price_unit' => 'USD',
        'to' => CUSTOMER, 'body' => BODY,
    ];
    $adapter = new TwilioSmsAdapter();

    return SendResult::accepted($sid, null, null, $adapter->usage($body, $sid));
}

echo "\nUsage metering\n";

check('an accepted Twilio send is one sms.segments event, attributed from the message row', static function (): void {
    reset();
    $dir = meterInto();
    $sid = 'SM' . str_repeat('a1', 16);

    $sent = send(twilioAccepted($sid));
    assertSame('accepted', $sent['outcome'], 'the send is accepted');

    $events = spooled($dir);
    assertSame(1, count($events), 'one event');
    $e = $events[0];
    assertSame('twilio:sms:' . $sid, $e['idempotency_key'], 'keyed on the provider message SID');
    assertSame(UsageMeter::eventIdFor('messaging', 'twilio:sms:' . $sid), $e['event_id'], 'so the event id is the one a correction names');
    assertSame(['category' => 'sms', 'provider' => 'twilio', 'provider_request_id' => $sid],
        ['category' => $e['category'], 'provider' => $e['provider'], 'provider_request_id' => $e['provider_request_id']], 'provider');
    assertSame([['meter' => 'sms.segments', 'quantity' => 2]], $e['usage'], 'the segments Twilio counted');
    assertSame(['certainty' => 'pending'], $e['vendor_cost'], 'price not known at send: pending, not invented');
    assertSame(CMP, $e['company_id'], 'the company is the message\'s');
    assertSame('docs', $e['origin_app'], 'the product whose key sent it ("drive" is Drive\'s catalog id "docs")');
    assertSame('user-owner', $e['user_uuid'], 'the person who wrote it');
    assertTrue(!isset($e['metadata']), 'segments reported: no metadata');
    assertNoContent($dir);
});

check('a price on a later status callback corrects the send, once, without changing its attribution', static function (): void {
    reset();
    $dir = meterInto();
    $sid = 'SM' . str_repeat('b2', 16);
    $sent = send(twilioAccepted($sid));
    $connection = ChannelConnection::find(CMP, CONNECTION);
    $adapter = new TwilioSmsAdapter();

    foreach (['sent', 'delivered'] as $status) {
        foreach ($adapter->normaliseWebhook([
            'MessageSid' => $sid, 'MessageStatus' => $status, 'To' => CUSTOMER, 'Price' => '-0.00790', 'PriceUnit' => 'usd',
        ]) as $event) {
            WebhookService::process($connection, $event);
        }
    }

    $events = spooled($dir);
    assertSame(3, count($events), 'the send, and the price from each of two callbacks');
    [$send, $first, $second] = $events;
    assertSame('correction', $first['event_kind'], 'the price is a correction');
    assertSame($send['event_id'], $first['corrects_event_id'], 'of the send');
    assertSame('twilio:sms:' . $sid . ':price', $first['idempotency_key'], 'keyed per SID');
    assertSame([['meter' => 'sms.segments', 'quantity' => 0]], $first['usage'], 'no more segments');
    assertSame(['amount' => '0.00790', 'currency' => 'USD', 'certainty' => 'confirmed'], $first['vendor_cost'], 'the provider\'s own price, positive');
    assertTrue(!isset($first['company_id']) && !isset($first['origin_app']) && !isset($first['user_uuid']), 'a correction keeps the original\'s attribution');
    assertSame($first, $second, 'the second callback is the same event (Manage keeps one)');
    assertSame('delivered', (string) Db::scalar('SELECT status FROM messaging_messages WHERE message_uuid = :u', ['u' => $sent['message']['message_uuid']]), 'and the receipts still moved the message');
    assertNoContent($dir);
});

check('a send priced at send time carries the confirmed price and is not corrected again', static function (): void {
    reset();
    $dir = meterInto();
    $sid = 'SM' . str_repeat('c3', 16);
    $adapter = new TwilioSmsAdapter();
    $body = ['sid' => $sid, 'num_segments' => '1', 'price' => '-0.0079', 'price_unit' => 'USD'];
    send(SendResult::accepted($sid, 1, 'USD', $adapter->usage($body, $sid)));

    foreach ($adapter->normaliseWebhook(['MessageSid' => $sid, 'MessageStatus' => 'delivered', 'Price' => '-0.0079', 'PriceUnit' => 'USD']) as $event) {
        WebhookService::process(ChannelConnection::find(CMP, CONNECTION), $event);
    }

    $events = spooled($dir);
    assertSame(1, count($events), 'only the send');
    assertSame(['amount' => '0.0079', 'currency' => 'USD', 'certainty' => 'confirmed'], $events[0]['vendor_cost'], 'confirmed at send');
});

check('Twilio: an answer with no segment count is one segment and says so; the same SID is the same key', static function (): void {
    $adapter = new TwilioSmsAdapter();
    $usage = $adapter->usage(['sid' => 'SM1', 'price' => 'not-a-price'], 'SM1');

    assertSame(1, $usage['units'], 'one segment assumed');
    assertSame(['segments_reported' => false], $usage['metadata'], 'and flagged as assumed');
    assertSame(['certainty' => 'pending'], $usage['vendor_cost'], 'an unreadable price is not a price');
    assertSame($usage['idempotency_key'], $adapter->usage(['num_segments' => '3'], 'SM1')['idempotency_key'], 'a status lookup of the same message reports the same operation');
    assertSame([], $adapter->priceCorrection(['MessageSid' => 'SM1', 'MessageStatus' => 'delivered'], 'SM1'), 'a callback with no price corrects nothing');
});

check('WhatsApp: one event per message from the first status carrying pricing, never at send', static function (): void {
    reset();
    $dir = meterInto();
    $sent = send(SendResult::accepted('prov-wa'));
    assertSame([], spooled($dir), 'nothing at send: the send answer says nothing about price');

    // A wamid encodes the recipient's number (this one decodes to 919811122233).
    $wamid = 'wamid.HBgMOTE5ODExMTIyMjMzFQIAERgSQTFCMkMzRDRFNUY2MDcA';
    Db::run('UPDATE messaging_messages SET provider_message_id = :pid WHERE message_uuid = :u', ['pid' => $wamid, 'u' => $sent['message']['message_uuid']]);
    $pricing = ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'utility', 'type' => 'regular'];
    $status = static fn (string $s, ?array $p): array => ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['value' => [
        'metadata' => ['display_phone_number' => '919800000000'],
        'statuses' => [array_filter(['id' => $wamid, 'status' => $s, 'timestamp' => '1780286400', 'recipient_id' => '919811122233', 'pricing' => $p])],
    ]]]]]];

    $adapter = new WhatsAppCloudAdapter();
    $connection = ChannelConnection::find(CMP, CONNECTION);
    foreach ([['sent', $pricing], ['delivered', $pricing], ['read', null]] as [$s, $p]) {
        foreach ($adapter->normaliseWebhook($status($s, $p)) as $event) {
            WebhookService::process($connection, $event);
        }
    }

    $events = spooled($dir);
    assertSame(2, count($events), 'the two statuses that carried pricing');
    assertSame($events[0], $events[1], 'are the same event (same id, same content), so Manage counts one message');
    $e = $events[0];
    assertSame('meta:wa:' . substr(hash('sha256', $wamid), 0, 40), $e['idempotency_key'], 'keyed on the message id\'s digest');
    assertSame(['whatsapp', 'meta_whatsapp', 'utility'], [$e['category'], $e['provider'], $e['service']], 'the pricing category is the service');
    assertSame([['meter' => 'whatsapp.messages', 'quantity' => 1]], $e['usage'], 'one message');
    assertSame(['billable' => true, 'pricing_model' => 'PMP', 'pricing_type' => 'regular'], $e['metadata'], 'billable and pricing model');
    assertTrue(!isset($e['vendor_cost']), 'no amount: the webhook carries none');
    assertSame([CMP, 'docs', 'user-owner'], [$e['company_id'], $e['origin_app'], $e['user_uuid']], 'attributed from the message row');
    assertNoContent($dir, $wamid, 'OTE5ODExMTIyMjMz');
});

check('RCS: one message per send, keyed on our message id, never the provider path holding the number', static function (): void {
    $adapter = new RcsAdapter();
    $uuid = Uuid::v4();
    $usage = $adapter->usage($uuid, ['name' => 'phones/' . CUSTOMER . '/agentMessages/abc']);

    assertSame('rcs:msg:' . $uuid, $usage['idempotency_key'], 'our id, the same on every attempt');
    assertSame(['sms', 'rcs', 'sms.segments', 1], [$usage['category'], $usage['provider'], $usage['meter'], $usage['units']], 'one message');
    assertSame(null, $usage['provider_request_id'], 'the RBM name is not sent');
    assertSame('abc-1', $adapter->usage($uuid, ['messageId' => 'abc-1'])['provider_request_id'], 'a plain provider id is');

    reset();
    $dir = meterInto();
    send(SendResult::accepted('abc-1', null, null, $usage), 'reach');
    $e = spooled($dir)[0];
    assertSame('messaging', $e['origin_app'], 'a caller with no catalog id is booked to Messaging itself');
    assertNoContent($dir);
});

check('the calling product is a catalog id or Messaging; a synthetic actor is no user', static function (): void {
    assertSame(['docs', 'appointments', 'buddy', 'messaging', 'messaging'], [
        MessagingMeter::originApp('drive'), MessagingMeter::originApp('appointments'), MessagingMeter::originApp('PULSE'),
        MessagingMeter::originApp('reach'), MessagingMeter::originApp(null),
    ], 'origin_app');

    reset();
    $dir = meterInto();
    $sid = 'SM' . str_repeat('d4', 16);
    $sent = send(twilioAccepted($sid), 'appointments');
    Db::run('UPDATE messaging_messages SET author_uuid = :a WHERE message_uuid = :u', ['a' => 'service:appointments', 'u' => $sent['message']['message_uuid']]);
    $row = Db::first('SELECT * FROM messaging_messages WHERE message_uuid = :u', ['u' => $sent['message']['message_uuid']]);
    MessagingMeter::recordForMessage((new TwilioSmsAdapter())->usage(['num_segments' => '2'], $sid), ['provider_accepted_at' => null] + $row);
    $events = spooled($dir);
    assertSame('appointments', $events[1]['origin_app'], 'the service key\'s product');
    assertTrue(!isset($events[1]['user_uuid']), '"service:appointments" is not a person');
    assertSame($events[0]['event_id'], $events[1]['event_id'], 'and the same SID is the same event');
});

check('the send is the same with metering off, and with metering broken', static function (): void {
    reset();
    $off = meterInto(null, '0');
    $a = send(twilioAccepted('SM' . str_repeat('e5', 16)));
    assertSame([], glob($off . '/*') ?: [], 'off: nothing is spooled');

    $file = tempnam(sys_get_temp_dir(), 'not-a-dir');
    meterInto($file . '/spool', '1', '');   // a spool that cannot be created, and nowhere to send
    $b = send(twilioAccepted('SM' . str_repeat('f6', 16)));
    @unlink($file);

    foreach ([$a, $b] as $i => $r) {
        assertSame('accepted', $r['outcome'], "send {$i} accepted");
        assertSame('provider_accepted', $r['message']['status'], "send {$i} provider_accepted");
    }
    assertSame($a['message']['provider_message_id'] !== null, $b['message']['provider_message_id'] !== null, 'the provider id is kept either way');
});

cleanUp();
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
