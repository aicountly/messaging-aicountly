<?php

declare(strict_types=1);

namespace Aicountly\Api\Lifecycle;

use Aicountly\Api\Clients\DriveClient;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\AttachmentService;
use Aicountly\Api\Env;
use Aicountly\Api\Http;
use Aicountly\Api\Router;
use Aicountly\Lifecycle\HookContext;
use Aicountly\Lifecycle\Kit;
use Aicountly\Lifecycle\PurgeEngine;
use PDO;

require_once __DIR__ . '/../../lib/company-lifecycle/autoload.php';

/**
 * AICOUNTLY company lifecycle in Messaging — the receiver kit (lib/company-lifecycle, see its
 * README) wired into this API. Contract: manage-aicountly docs/company-lifecycle/README.md.
 *
 *   POST /api/internal/manage/company-lifecycle   receive()  — HMAC only, no session (Routes.php)
 *   Context::assertAllowed()                       guard()    — 423 COMPANY_RESTRICTED after the
 *                                                               Manage company check passed; a
 *                                                               product's service key (no person)
 *                                                               goes through checkMachine() (kit 1.1)
 *   WebhookService::receive()                      machineRefusal() — provider webhooks, in
 *                                                               MACHINE_ALLOW (never refused)
 *                                                  syncFromCompanyInfo() — companyinfo's lifecycle
 *   bin/worker.php --bootstrap=config/lifecycle-kit.php       — cron: purges + acks (docs/DEPLOYMENT.md)
 *
 * The purge itself is the reviewed manifest config/lifecycle-manifest.php. What lives outside the
 * database goes with it (hooks()): attachment files in MESSAGING_ATTACHMENT_DIR and attachments held
 * in Aicountly Drive are deleted (ObjectSweep, retried and recorded in lifecycle_object_deletions);
 * channel credentials are never stored here (credential_ref names a server variable or a Console
 * secret), so the ack names the references ops must remove. Inside the database, the retained audit
 * trail's scrub is a before-hook (auditScrubHook): messaging_audit_events is append-only, and migration
 * 012_audit_trail_lifecycle_purge.sql lets only the lifecycle worker through.
 */
final class CompanyLifecycle
{
    public const APP_KEY = 'messaging';

    /**
     * Writes that stay open while a company is read-only: they only read, render or export, or are the
     * person's own UI state. GET/HEAD/OPTIONS always pass. tests/lifecycle.php checks each entry
     * matches a real route.
     */
    public const ALLOW = [
        // checks a journey definition and answers; stores nothing
        'POST /api/v1/journeys/validate',
        // hides a Command Centre suggestion for this person (their own UI state)
        'DELETE /api/v1/suggestions/*',
    ];

    /**
     * Machine writes (kit 1.1, ReadOnlyGuard::checkMachine) that stay open while a company is read-only.
     * Every other write a product makes with its service key and no signed-in person — POST /api/v1/messages
     * from Appointments, Billing, Secretarial … — answers the same 423 COMPANY_RESTRICTED, and the sending
     * product defers it (kit DeferredSend). Manage's own lifecycle event is always open (the kit adds it).
     */
    public const MACHINE_ALLOW = [
        // Provider webhooks (WhatsApp / SMS / RCS): inbound customer messages, delivery receipts and
        // opt-outs. They are the company's CUSTOMERS writing in, or the carrier reporting what already
        // happened — a provider cannot replay them after renewal, and an opt-out that was dropped would
        // be a consent breach. Tenant resolved from the connection in the path, never from the payload.
        'POST /api/webhooks/*/*',
    ];

    /**
     * Background work (queued sends, journey runs) waits while a company is read-only: a SQL condition
     * on a table's cmp_id. Nothing is cancelled — it resumes when the subscription is renewed, and a
     * purge removes it.
     */
    public const NOT_RESTRICTED_SQL = "cmp_id NOT IN (SELECT target_cmp_id FROM aic_lifecycle_state WHERE state IN ('restricted', 'purge_pending', 'purged'))";

    private static ?Kit $kit = null;

    public static function kit(): Kit
    {
        return self::$kit ??= self::build(Db::connect());
    }

    /** @param array<string,mixed> $options extra Kit options (tests: http, clock, hooks) */
    public static function build(PDO $pdo, array $options = []): Kit
    {
        return Kit::fromEnv(self::APP_KEY, $pdo, self::manifestPath(), self::env(), [], $options + [
            'hooks' => self::hooks($pdo),
        ]);
    }

    public static function manifestPath(): string
    {
        return dirname(__DIR__, 2) . '/config/lifecycle-manifest.php';
    }

    /** @return callable(string): ?string the API's .env (Env), real environment variables first */
    public static function env(): callable
    {
        return static function (string $name): ?string {
            $value = Env::get($name);

            return $value !== '' ? $value : null;
        };
    }

    /** CLI only: a test's own Kit (fake Manage, clock), or null to build from .env again. */
    public static function overrideForTesting(?Kit $kit): void
    {
        if (PHP_SAPI === 'cli') {
            self::$kit = $kit;
        }
    }

    private static ?string $rawBodyForTesting = null;

    /** CLI only: the raw body the next receive() reads instead of php://input. */
    public static function setRawBodyForTesting(?string $raw): void
    {
        if (PHP_SAPI === 'cli') {
            self::$rawBodyForTesting = $raw;
        }
    }

    /** POST /api/internal/manage/company-lifecycle — the signature is the only credential. */
    public static function receive(): never
    {
        try {
            $kit = self::kit();
        } catch (\Throwable $e) {
            error_log('company-lifecycle [error]: receiver unavailable: ' . $e->getMessage());
            Http::json(503, ['error' => 'not_recorded', 'message' => 'The event could not be recorded right now; retry.']);
        }
        $raw = self::$rawBodyForTesting ?? Http::rawBody();
        self::$rawBodyForTesting = null;
        [$status, $json] = $kit->receiver()->handle($raw, $_SERVER);
        Http::json($status, $json);
    }

    /**
     * The write gate, called once the company check passed (so a caller who may not open the
     * company still gets 403). A write to a restricted company answers 423 COMPANY_RESTRICTED.
     * $machine: the caller is a product's service key with no signed-in person (kit 1.1
     * checkMachine(): ALLOW plus MACHINE_ALLOW, everything else 423 — the sender defers).
     */
    public static function guard(int $cmpId, bool $machine = false): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $refusal = $machine
            ? self::machineRefusal($cmpId, $method, self::requestPath())
            : self::refusal($cmpId, $method, self::requestPath());
        if ($refusal !== null) {
            Http::json($refusal[0], $refusal[1]);
        }
    }

    /**
     * A person's write: null (go ahead) or [423, payload].
     *
     * @return array{0:int, 1:array<string,mixed>}|null
     */
    public static function refusal(int $cmpId, string $method, string $path): ?array
    {
        try {
            return self::kit()->guard(self::ALLOW)->check($cmpId, $method, $path);
        } catch (\Throwable $e) {
            // A database fault must not become a false "your subscription ended".
            error_log('company-lifecycle [warning]: guard skipped for cmp=' . $cmpId . ': ' . $e->getMessage());

            return null;
        }
    }

    /**
     * A machine write (service key, provider webhook): null (go ahead) or [423, payload].
     *
     * @return array{0:int, 1:array<string,mixed>}|null
     */
    public static function machineRefusal(int $cmpId, string $method, string $path): ?array
    {
        try {
            return self::kit()->guard(self::ALLOW, self::MACHINE_ALLOW)->checkMachine($cmpId, $method, $path);
        } catch (\Throwable $e) {
            // A database fault must not become a false "your subscription ended".
            error_log('company-lifecycle [warning]: machine guard skipped for cmp=' . $cmpId . ': ' . $e->getMessage());

            return null;
        }
    }

    /** Manage's companyinfo is the source of truth: write its lifecycle through. */
    public static function syncFromCompanyInfo(int $cmpId, mixed $body): void
    {
        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : null;
        if ($data === null || !isset($data['lifecycle'])) {
            return;
        }
        try {
            self::kit()->guard()->syncFromCompanyInfo($cmpId, $data);
        } catch (\Throwable $e) {
            error_log('company-lifecycle [warning]: companyinfo lifecycle not recorded for cmp=' . $cmpId . ': ' . $e->getMessage());
        }
    }

    /**
     * The company's lifecycle here, for the app's read-only banner: {lifecycle, restricted_until,
     * message} while it is read-only, null when it is active (or the state cannot be read).
     *
     * @return array{lifecycle:string, restricted_until:?string, message:string}|null
     */
    public static function stateFor(int $cmpId): ?array
    {
        try {
            $state = self::kit()->store()->state($cmpId);
        } catch (\Throwable $e) {
            return null;
        }
        $name = (string) ($state['state'] ?? 'active');
        if (!in_array($name, \Aicountly\Lifecycle\ReadOnlyGuard::BLOCKING, true)) {
            return null;
        }
        $until = isset($state['restricted_until']) ? (string) $state['restricted_until'] : null;
        $payload = \Aicountly\Lifecycle\ReadOnlyGuard::payload($name, $until);

        return ['lifecycle' => $name, 'restricted_until' => $until, 'message' => (string) $payload['message']];
    }

    /** '/api/' + the routed path; outside the router, the request URI. */
    public static function requestPath(): string
    {
        $path = Router::currentPath();
        if ($path === null) {
            $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
            $path = preg_replace('#^/?(api/)?#', '', $uri) ?? '';
        }

        return '/api/' . trim($path, '/');
    }

    /** The retained audit table and the columns the manifest scrubs on it (tests/lifecycle.php keeps them in step). */
    public const AUDIT_TABLE = 'messaging_audit_events';

    public const AUDIT_SCRUBBED = ['before_state', 'after_state'];

    /**
     * Data outside the database, removed after the rows (kit hooks). Kit 1.1 passes every hook a
     * HookContext third argument; the after-hooks change no product row outside the engine (the sweep
     * writes only its own lifecycle_object_deletions bookkeeping, the credentials hook only reads the
     * archive), so they need no $ctx->archiveRows(). The one before-hook, auditScrubHook, does change
     * rows (the retained audit trail's scrub) and archives them first.
     *
     * @param array<string, callable> $deleters override a store's deleter (tests)
     * @return array{company_before: list<callable>, company: list<callable>, account: list<callable>}
     */
    public static function hooks(PDO $pdo, array $deleters = [], ?callable $sleep = null): array
    {
        $sweep = new ObjectSweep($pdo, [
            'messaging_message_attachments' => static function (array $row): array {
                $store = (string) ($row['storage'] ?? '');
                $ref = (string) ($row['storage_ref'] ?? '');

                // 'provider' is a media id the provider holds and expires; Messaging never copied it.
                return in_array($store, ['local', 'drive'], true) && $ref !== '' ? [[$store, $ref]] : [];
            },
        ], $deleters + [
            'local' => static function (string $key): void {
                $path = AttachmentService::localPath($key);
                if ($path === null) {
                    throw new \RuntimeException('MESSAGING_ATTACHMENT_DIR is not set, or the key is not an attachment key');
                }
                if (is_file($path) && !unlink($path)) {
                    throw new \RuntimeException('could not delete the file');
                }
            },
            'drive' => static function (string $key, array $row): void {
                $r = (new DriveClient())->deleteDocument($key, 'aicountly-company-lifecycle:' . $row['target_kind'] . ':' . $row['target_ref'],
                    'AICOUNTLY Manage company lifecycle: the company\'s Messaging data was purged.');
                if (!$r['ok']) {
                    throw new \RuntimeException('Drive: ' . (string) $r['error']);
                }
            },
        ], null, $sleep);

        /** @var array<string,int> $scrubbed rows redacted per company, from the before-hook to the after-hook */
        $scrubbed = [];

        return [
            'company_before' => [self::auditScrubHook($scrubbed)],
            'company'        => [$sweep->hook('company'), self::channelCredentialsHook($pdo), self::auditScrubReport($scrubbed)],
            'account'        => [$sweep->hook('account')],
        ];
    }

    /**
     * company_before: the retained audit trail's scrub (manifest `scrub`: before_state / after_state
     * redacted). messaging_audit_events is append-only (009_messaging_audit_trail), and the engine's own
     * scrub runs inside its purge transaction, where nothing may say "this is the lifecycle purge". So
     * this hook does it first, the one way migration 012_audit_trail_lifecycle_purge.sql allows: the
     * company's rows not yet redacted are archived ($ctx->archiveRows, scrubbed there too by the
     * manifest's rule) and redacted in one transaction under SET LOCAL aicountly.lifecycle_purge = on.
     * Same value and same rows as the engine's scrub (PurgeEngine::REDACTED, jsonb), which then finds
     * nothing left to change. A failure is thrown: the kit fails the attempt and retries the job.
     *
     * @param array<string,int> $scrubbed
     * @return callable(int|string, array<string,mixed>, HookContext): ?string
     */
    public static function auditScrubHook(array &$scrubbed): callable
    {
        return static function (int|string $cmpId, array $result, HookContext $ctx) use (&$scrubbed): ?string {
            $db = $ctx->pdo();
            $redacted = "CAST('\"" . PurgeEngine::REDACTED . "\"' AS JSONB)";
            $set = [];
            $differs = [];
            foreach (self::AUDIT_SCRUBBED as $col) {
                $set[] = "{$col} = {$redacted}";
                $differs[] = "{$col} IS DISTINCT FROM {$redacted}";
            }
            $where = 'cmp_id = ? AND (' . implode(' OR ', $differs) . ')';
            $db->beginTransaction();
            try {
                $db->exec("SELECT set_config('aicountly.lifecycle_purge', 'on', true)");
                $ctx->archiveRows(self::AUDIT_TABLE, $where, [(int) $cmpId]);
                $stmt = $db->prepare('UPDATE ' . self::AUDIT_TABLE . ' SET ' . implode(', ', $set) . ' WHERE ' . $where);
                $stmt->execute([(int) $cmpId]);
                $scrubbed[(string) $cmpId] = $stmt->rowCount();
                $db->commit();
            } catch (\Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }

            return null;
        };
    }

    /**
     * company (after the engine): the ack line the engine writes for its own scrub, for the rows
     * auditScrubHook redacted.
     *
     * @param array<string,int> $scrubbed
     * @return callable(int|string, array<string,mixed>, HookContext=): ?string
     */
    public static function auditScrubReport(array &$scrubbed): callable
    {
        return static function (int|string $cmpId, array $result, ?HookContext $ctx = null) use (&$scrubbed): ?string {
            $n = $scrubbed[(string) $cmpId] ?? 0;
            unset($scrubbed[(string) $cmpId]);

            return $n > 0 ? sprintf('Personal fields scrubbed in %d retained row(s) (public.%s).', $n, self::AUDIT_TABLE) : null;
        };
    }

    /**
     * Channel credentials are never in this database: credential_ref / webhook_secret_ref name a
     * server environment variable or a Console secret (console:<name>). Nothing to revoke here — the
     * WhatsApp / SMS / RCS sender stops working for this company with the connection rows gone (every
     * webhook resolves its tenant from the connection) — but the secrets those names point at belong
     * to the purged company, so the ack names them for ops to remove.
     */
    private static function channelCredentialsHook(PDO $pdo): callable
    {
        return static function (int|string $cmpId, array $result, ?HookContext $ctx = null) use ($pdo): ?string {
            $archive = is_string($result['archive'] ?? null) ? $result['archive'] : '';
            if ($archive === '' || !isset($result['counts']['public.messaging_channel_connections'])) {
                return null;
            }
            $refs = [];
            foreach ($pdo->query('SELECT channel, sender_address, credential_ref, webhook_secret_ref FROM "' . $archive . '".messaging_channel_connections')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                foreach (['credential_ref', 'webhook_secret_ref'] as $col) {
                    if (trim((string) $c[$col]) !== '') {
                        $refs[trim((string) $c[$col])] = true;
                    }
                }
            }
            $n = (int) $result['counts']['public.messaging_channel_connections'];
            $line = sprintf('%d channel connection(s) disconnected and removed.', $n);
            if ($refs !== []) {
                $names = implode(', ', array_keys($refs));
                error_log('company-lifecycle [warning]: cmp=' . $cmpId . ' purged; remove the channel secrets these references name: ' . $names);
                $line .= ' Their credentials are server/Console secrets, not stored here; ops remove: ' . $names . '.';
            }

            return $line;
        };
    }
}
