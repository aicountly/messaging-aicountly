<?php

declare(strict_types=1);

/**
 * Messaging ↔ Contacts and Manage conformance, against the REAL handlers.
 *
 * Not part of tests/run.sh: it needs the e2e harness (real Contacts + real
 * Manage + the real my.aicountly validatesession code, integer user ids) and a
 * migrated Messaging test database (tests/run.sh once):
 *
 *   /home/user/e2e/bin/up.sh --agent vm --port-base 20000
 *   /home/user/e2e/bin/with-stack.sh --agent vm -- php server-php/tests/contacts-conformance.php
 *
 * Fixture (harness README): A=101 owns X=501, B=102 member of X, C=103 owns
 * Y=502, D=104 removed from X; X company contact "Kiran Vendor" (+919845098765)
 * created by A; A's personal contact "Bala Member". Everything goes through
 * Messaging's own code (ContactsClient over the vendored shared client,
 * Context over ManageCompanyAnswer, BusinessContextService) — no stub.
 */

namespace Aicountly\Api;

use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Domain\BusinessContextService;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;
use AicountlyContacts\ContactsApiClient;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

foreach (['E2E_CONTACTS_URL', 'E2E_MANAGE_ORIGIN', 'E2E_PORTAL_ORIGIN', 'E2E_SES_A', 'E2E_SES_B', 'E2E_SES_C', 'E2E_SES_D'] as $var) {
    if ((string) getenv($var) === '') {
        fwrite(STDERR, "missing {$var}: run through /home/user/e2e/bin/with-stack.sh --agent vm\n");
        exit(2);
    }
}

// Point Messaging at the stack (Contacts with /api, as in production).
putenv('CONTACTS_API_BASE=' . getenv('E2E_CONTACTS_URL'));
putenv('MANAGE_API_BASE=' . getenv('E2E_MANAGE_ORIGIN'));
putenv('PORTAL_AUTH_BASE=' . getenv('E2E_PORTAL_ORIGIN'));
putenv('MESSAGING_CONTACTS_ENABLED=1');
putenv('AIC_ENVIRONMENT=local');

$fixture = json_decode((string) @file_get_contents((string) (getenv('E2E_FIXTURE_JSON') ?: '/home/user/e2e/run/vm/fixture.json')), true) ?: [];
$kiran = (string) ($fixture['X_company_kiran']['id'] ?? '');
$bala = (string) ($fixture['A_personal_bala']['id'] ?? '');
const X = 501;

$passed = 0;
$failed = [];
$ok = static function (bool $condition, string $what) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "  ok   {$what}\n";
    } else {
        $failed[] = $what;
        echo "  FAIL {$what}\n";
    }
};
$client = static fn (string $ses): ContactsClient => (new ContactsClient())->withSession((string) getenv($ses));
$signIn = static function (string $ses): ?Auth {
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . getenv($ses);
    unset($_SERVER['HTTP_X_SERVICE_KEY']);
    $auth = Auth::resolve();
    unset($_SERVER['HTTP_AUTHORIZATION']);

    return $auth;
};
$opens = static function (Auth $auth, int $cmp): int {
    try {
        Context::forCompany($cmp)->assertAllowed($auth);

        return 200;
    } catch (ResponseSent $e) {
        return $e->status;
    }
};

echo 'Messaging -> Contacts/Manage conformance (real handlers at ' . getenv('E2E_CONTACTS_URL') . ")\n";

// --- G19#8: ownership from the REAL Manage companyinfo, sessions from the REAL portal ---
$a = $signIn('E2E_SES_A');
$ok($a !== null && $a->uuid === '101', 'A is resolved by the real validatesession as user 101 (no acs_type)');
$ok($opens($a, X) === 200 && Context::forCompany(X)->isOwner($a), 'the real Manage companyinfo says A OWNS X');
$b = $signIn('E2E_SES_B');
$ok($opens($b, X) === 200 && !Context::forCompany(X)->isOwner($b), 'B opens X as a member, not an owner');
$ok(!Permissions::allows(Context::forCompany(X), $b, 'messaging.access.manage'), 'so B cannot manage Messaging access');
$ok($opens($signIn('E2E_SES_C'), X) === 403, 'C (owns another company) is refused X: 403');
$ok($opens($signIn('E2E_SES_D'), X) === 403, 'D (removed from X in Manage) is refused: 403');

// --- G19#3 / G19#2: the COMPANY directory, canonical fields --------------------
$found = $client('E2E_SES_B')->search(X, 'kiran', 10, 0);
$row = $found['body']['data'][0] ?? [];
$ok($found['ok'] && ($row['name'] ?? '') === 'Kiran Vendor', 'B (member, not the creator) finds "Kiran Vendor" in the company directory (displayName)');
$ok(($row['mobile'] ?? '') === '+919845098765', 'with the phone from phones[{value}] as E.164');
$ok((int) ($found['body']['meta']['total'] ?? -1) === 1, 'meta.total is the real count');
$personal = $client('E2E_SES_A')->contact(X, $bala);
$ok(!$personal['ok'] && in_array($personal['state'], ['unsupported', 'forbidden'], true), "A's PERSONAL contact is not the inbox's: not readable through company X ({$personal['error']})");
$names = $client('E2E_SES_B')->resolveMany(X, [$kiran, $bala]);
$ok($names['ok'] && array_column((array) $names['body']['data'], 'requested_id') === [$kiran], 'resolve-many names the company contact only');

// --- G19#1: lookup + matchCount --------------------------------------------------
$hit = $client('E2E_SES_B')->lookupPhone(X, '+919845098765');
$ok($hit['ok'] && ($hit['body']['meta']['matchCount'] ?? null) === 1 && ($hit['body']['meta']['attributable'] ?? false) === true, 'Kiran\'s number: matchCount 1, attributable');
$miss = $client('E2E_SES_B')->lookupPhone(X, '+919845000000');
$ok($miss['ok'] && ($miss['body']['meta']['matchCount'] ?? null) === 0 && $miss['body']['data'] === [], 'an unknown number matches nobody (no first-row fallback)');
$cOnX = $client('E2E_SES_C')->lookupPhone(X, '+919845098765');
$ok(!$cOnX['ok'] && $cOnX['state'] === 'forbidden' && $cOnX['error'] === 'contacts_forbidden', 'C looking up in X: forbidden, labelled as such');

// The customer panel, through Messaging's own BusinessContextService.
Db::run('DELETE FROM messaging_conversations WHERE cmp_id = :c', ['c' => X]);
Db::run('DELETE FROM messaging_channel_connections WHERE cmp_id = :c', ['c' => X]);
$line = Uuid::v4();
Db::insert('messaging_channel_connections', [
    'connection_uuid' => $line, 'cmp_id' => X, 'bo_id' => 0, 'channel' => 'whatsapp', 'provider' => 'test_provider',
    'display_name' => 'E2E line', 'sender_address' => '+919800000501', 'provider_account_ref' => 'acct-e2e',
    'credential_ref' => 'MESSAGING_TEST_TOKEN', 'webhook_secret_ref' => 'MESSAGING_TEST_WEBHOOK_SECRET',
    'status' => 'connected', 'is_active' => true, 'created_at' => Clock::nowSql(), 'updated_at' => Clock::nowSql(),
], 'connection_uuid');
$conversation = static function (string $address, ?string $contact) use ($line) {
    $uuid = Uuid::v4();
    Db::insert('messaging_conversations', [
        'conversation_uuid' => $uuid, 'cmp_id' => X, 'bo_id' => 0, 'connection_uuid' => $line,
        'channel' => 'whatsapp', 'customer_address' => $address, 'contact_uuid' => $contact, 'status' => 'open',
        'first_inbound_at' => Clock::nowSql(), 'last_inbound_at' => Clock::nowSql(), 'created_at' => Clock::nowSql(), 'updated_at' => Clock::nowSql(),
    ], 'conversation_uuid');

    return (array) Db::first('SELECT * FROM messaging_conversations WHERE conversation_uuid = :u', ['u' => $uuid]);
};
$panel = BusinessContextService::for(Context::forCompany(X), $b, $conversation('+919845098765', null))['contact'];
$ok(($panel['data']['suggestion']['name'] ?? '') === 'Kiran Vendor' && $panel['data']['matched'] === false, 'an inbound from Kiran\'s number OFFERS Kiran (not linked until an agent says so)');
$panel = BusinessContextService::for(Context::forCompany(X), $b, $conversation('+919845011111', null))['contact'];
$ok(($panel['data']['match_count'] ?? null) === 0 && !isset($panel['data']['suggestion']), 'a stranger stays unknown');
$panel = BusinessContextService::for(Context::forCompany(X), $b, $conversation('+919845022222', $kiran))['contact'];
$ok(($panel['data']['linked'] ?? false) === true && ($panel['data']['name'] ?? '') === 'Kiran Vendor', 'a linked conversation reads its company contact live');

// --- G19#5: an explicit Books ledger reference in Contacts ------------------------
// A fresh company contact each run, so the run is repeatable.
$raw = (new ContactsApiClient((string) getenv('E2E_CONTACTS_URL')))->withSession((string) getenv('E2E_SES_A'));
try {
    $tag = bin2hex(random_bytes(4));
    $made = $raw->createCompanyContact(X, ['displayName' => 'Ledger Probe ' . $tag], 'vm-conf-contact-' . $tag);
    $probe = (string) ($made['data']['id'] ?? '');
    $none = $client('E2E_SES_B')->ledgerAccount(X, $probe);
    $ok($none['ok'] && $none['body']['data'] === null, 'a company contact with no Books ledger reference: none is assumed');
    // Contacts lets one ledger account belong to one contact per company.
    $acc = (string) random_int(700000, 799999);
    $raw->addReference(X, $probe, 'books', 'ledger_account', $acc, 'Probe A/c', 'vm-conf-ledger-' . $tag);
    $linked = $client('E2E_SES_B')->ledgerAccount(X, $probe);
    $ok($linked['ok'] && ($linked['body']['data']['acc_id'] ?? null) === $acc, "after A links ledger {$acc} in Contacts, Messaging reads that acc_id from it");
} catch (\Throwable $e) {
    $ok(false, 'the ledger reference round trip: ' . $e->getMessage());
}

echo "\n{$passed} passed, " . count($failed) . " failed\n";
foreach ($failed as $f) {
    echo "  - {$f}\n";
}
exit($failed === [] ? 0 : 1);
