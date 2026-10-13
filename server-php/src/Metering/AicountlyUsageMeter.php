<?php
declare(strict_types=1);

namespace Aicountly\Metering;

/**
 * AICOUNTLY usage meter — the one client every product uses to report AI and third-party usage to
 * Manage (docs/usage-metering/ in manage-aicountly). Version: see VERSION next to this file.
 *
 * COPY, DON'T EDIT: this file lives in manage-aicountly "Saas Common Handler/usage-metering/php/".
 * Products copy it unchanged (it has no dependencies: PHP 8.1+, ext-curl, ext-json), so a fix is
 * made once and copied out.
 *
 * Behaviour, in one paragraph: record*() never throws and never blocks the user. It writes the
 * event to a local spool directory (one small file per event, written atomically) and returns.
 * After the response has been sent (fastcgi_finish_request / litespeed_finish_request) one batch
 * is delivered to Manage with a short timeout; anything not delivered stays in the spool and is
 * sent by the next request or by the cron drain (`php usage-meter-flush.php`). Delivery is
 * at-least-once; Manage makes it exactly-once with the idempotency key. Events Manage refuses as
 * invalid move to spool/dead/ with the reason and are logged — never silently dropped.
 *
 * What is never sent: prompts, completions, message bodies, recipients, API keys, tokens. Only
 * counts, units, provider/model names, ids and the company/user the usage was for.
 *
 * Phase 1 measures only: nothing here can refuse or slow a user's action because of usage.
 *
 * Configuration (environment, or the $config array of fromEnv()):
 *   AICOUNTLY_METERING_ENABLED      1 to send. Default 0 (events are not even spooled when 0).
 *   AICOUNTLY_METERING_URL          Manage API base, e.g. https://manage.aicountly.com/api
 *                                   (falls back to MANAGE_API_BASE / MANAGE_API_URL / MANAGE_API_ORIGIN)
 *   AICOUNTLY_METERING_KEY          this product's labelled Manage service key (falls back to
 *                                   MANAGE_PRODUCT_SERVICE_KEY). Manage lists it in
 *                                   MANAGE_PRODUCT_SERVICE_KEYS as <product>:<key>.
 *   AICOUNTLY_METERING_SOURCE       this product's catalog id (books, buddy, messaging, …)
 *   AICOUNTLY_METERING_ENVIRONMENT  production | sandbox | development (default production)
 *   AICOUNTLY_METERING_SPOOL_DIR    writable directory (default <sys temp>/aicountly-metering-<source>)
 *   AICOUNTLY_METERING_FLUSH        shutdown (default) | cron (spool only; drain with the CLI)
 */
final class UsageMeter
{
    public const VERSION        = '1.0.0';
    public const SCHEMA_VERSION = '1';
    private const BATCH         = 100;
    private const MAX_ATTEMPTS  = 200;

    /** @var array<string,string> */
    private array $config;

    /** @var (callable(string,string,array<int,string>,int,int):array{0:int,1:string})|null test seam */
    private $transport;

    private bool $shutdownRegistered = false;

    /** @var list<array<string,mixed>> events kept in memory when the spool is not writable */
    private array $memory = [];

    private static ?self $shared = null;

    /** @param array<string,string> $config */
    public function __construct(array $config, ?callable $transport = null)
    {
        $this->config    = $config;
        $this->transport = $transport;
    }

    /**
     * Build from the environment. $env reads one key (pass your framework's env() / Env::get);
     * default getenv() then $_ENV then $_SERVER.
     *
     * @param (callable(string):?string)|null $env
     * @param array<string,string> $overrides
     */
    public static function fromEnv(?callable $env = null, array $overrides = []): self
    {
        $read = $env ?? static function (string $k): ?string {
            $v = getenv($k);
            if ($v !== false && $v !== '') {
                return $v;
            }
            $v = $_ENV[$k] ?? $_SERVER[$k] ?? null;

            return is_string($v) && $v !== '' ? $v : null;
        };
        $first = static function (array $keys) use ($read): string {
            foreach ($keys as $k) {
                $v = $read($k);
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }

            return '';
        };
        $source = strtolower($first(['AICOUNTLY_METERING_SOURCE', 'APP_PRODUCT_KEY']));

        return new self($overrides + [
            'enabled'     => $first(['AICOUNTLY_METERING_ENABLED']),
            'url'         => $first(['AICOUNTLY_METERING_URL', 'MANAGE_API_BASE', 'MANAGE_API_URL', 'MANAGE_API_ORIGIN']),
            'key'         => $first(['AICOUNTLY_METERING_KEY', 'MANAGE_PRODUCT_SERVICE_KEY']),
            'source'      => $source,
            'environment' => $first(['AICOUNTLY_METERING_ENVIRONMENT']) ?: 'production',
            'spool_dir'   => $first(['AICOUNTLY_METERING_SPOOL_DIR']),
            'flush'       => $first(['AICOUNTLY_METERING_FLUSH']) ?: 'shutdown',
        ]);
    }

    /** One meter per process, built from the environment on first use. */
    public static function shared(?callable $env = null): self
    {
        return self::$shared ??= self::fromEnv($env);
    }

    /** Tests: replace or clear the shared meter. */
    public static function setShared(?self $meter): void
    {
        self::$shared = $meter;
    }

    public function enabled(): bool
    {
        return in_array(strtolower($this->config['enabled'] ?? ''), ['1', 'true', 'on', 'yes'], true);
    }

    // ── Recording ────────────────────────────────────────────────────────────

    /**
     * Record one usage event (contract v1). Returns its event_id, or null when metering is off or
     * the event was unusable (logged). Never throws.
     *
     * Required: idempotency_key (stable for the provider operation: a retry of the same call must
     * produce the same key), category, provider, usage [{meter, quantity}]. Everything else is
     * optional; occurred_at defaults to now, environment to the configured one.
     *
     * @param array<string,mixed> $event
     */
    public function record(array $event): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        try {
            $event = $this->complete($event);
            $problem = self::problem($event);
            if ($problem !== null) {
                error_log('[aicountly-metering] event not recorded (' . $problem . '): ' . self::describe($event));

                return null;
            }
            $this->spool($event);
            $this->scheduleFlush();

            return (string) $event['event_id'];
        } catch (\Throwable $e) {
            error_log('[aicountly-metering] record failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * One model call, as the provider reported it. Token counts are the provider's own numbers.
     *
     * @param array{
     *   idempotency_key:string, provider:string, model?:?string, operation?:?string,
     *   provider_request_id?:?string, input_tokens?:int|string|null, output_tokens?:int|string|null,
     *   cached_input_tokens?:int|string|null, cache_write_tokens?:int|string|null, reasoning_tokens?:int|string|null,
     *   audio_seconds?:int|float|string|null, failed?:bool, outcome?:string, provider_status?:?string,
     *   vendor_cost?:?array, origin_app?:?string, feature?:?string, company_id?:?int, branch_id?:?int,
     *   user_uuid?:?string, correlation_id?:?string, occurred_at?:?string, metadata?:array, category?:string
     * } $a
     */
    public function recordAiUsage(array $a): ?string
    {
        $failed = (bool) ($a['failed'] ?? false);
        $usage  = [['meter' => 'ai.requests', 'quantity' => 1]];
        if ($failed) {
            $usage[] = ['meter' => 'ai.failed_requests', 'quantity' => 1];
        }
        foreach (['input_tokens', 'output_tokens', 'cached_input_tokens', 'cache_write_tokens', 'reasoning_tokens'] as $k) {
            $v = $a[$k] ?? null;
            if ($v !== null && $v !== '' && (string) $v !== '0') {
                $usage[] = ['meter' => 'ai.' . $k, 'quantity' => is_int($v) ? $v : (string) $v];
            }
        }
        if (($a['audio_seconds'] ?? null) !== null) {
            $usage[] = ['meter' => 'ai.audio_seconds', 'quantity' => self::num($a['audio_seconds'], 3)];
        }

        return $this->record(self::common($a) + [
            'category'           => $a['category'] ?? 'ai',
            'provider'           => $a['provider'],
            'service'            => $a['model'] ?? null,
            'provider_operation' => $a['operation'] ?? null,
            'outcome'            => $a['outcome'] ?? ($failed ? 'failed' : 'success'),
            'usage'              => $usage,
        ]);
    }

    /**
     * One call to a third-party provider that bills per operation (GSP, e-Invoice, SMS, WhatsApp,
     * e-mail API, payment gateway …). `units` is what the provider bills (segments, messages,
     * transactions), defaulting to 1 operation.
     *
     * @param array<string,mixed> $a idempotency_key, category, provider, meter, units?, operation?,
     *                               outcome?, vendor_cost? {amount,currency,certainty}, + common fields
     */
    public function recordProviderCall(array $a): ?string
    {
        return $this->record(self::common($a) + [
            'category'           => $a['category'],
            'provider'           => $a['provider'],
            'service'            => $a['service'] ?? null,
            'provider_operation' => $a['operation'] ?? null,
            'outcome'            => $a['outcome'] ?? 'success',
            'usage'              => [['meter' => $a['meter'], 'quantity' => $a['units'] ?? 1]],
        ]);
    }

    /** Time-based usage (call seconds, audio seconds, session seconds). @param array<string,mixed> $a */
    public function recordDuration(array $a): ?string
    {
        return $this->record(self::common($a) + [
            'category'           => $a['category'],
            'provider'           => $a['provider'],
            'service'            => $a['service'] ?? null,
            'provider_operation' => $a['operation'] ?? null,
            'outcome'            => $a['outcome'] ?? 'success',
            'usage'              => [['meter' => $a['meter'], 'quantity' => self::num($a['seconds'], 3)]],
        ]);
    }

    /** Storage volume (bytes written / held). @param array<string,mixed> $a */
    public function recordStorage(array $a): ?string
    {
        return $this->record(self::common($a) + [
            'category'           => 'storage',
            'provider'           => $a['provider'],
            'provider_operation' => $a['operation'] ?? null,
            'outcome'            => $a['outcome'] ?? 'success',
            'usage'              => [['meter' => $a['meter'] ?? 'storage.bytes_uploaded', 'quantity' => (int) $a['bytes']]],
        ]);
    }

    /**
     * Correct an event already reported (a provider reversal, a refund of a fee, a cost that arrived
     * later by callback). Quantities are signed deltas; the original stays on record.
     *
     * @param array<string,mixed> $a corrects_event_id, idempotency_key, category, provider, usage, vendor_cost?
     */
    public function recordAdjustment(array $a): ?string
    {
        return $this->record(self::common($a) + [
            'event_kind'        => 'correction',
            'corrects_event_id' => $a['corrects_event_id'],
            'category'          => $a['category'],
            'provider'          => $a['provider'],
            'service'           => $a['service'] ?? null,
            'outcome'           => $a['outcome'] ?? 'success',
            'usage'             => $a['usage'],
        ]);
    }

    /**
     * The event_id for an idempotency key: deterministic (UUIDv5-style over source + key), so the
     * same provider operation always maps to the same event, across retries and processes.
     */
    public static function eventIdFor(string $source, string $idempotencyKey): string
    {
        $h = sha1('aicountly-metering:' . $source . ':' . $idempotencyKey);

        return sprintf('%s-%s-5%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3),
            dechex((hexdec($h[16]) & 0x3) | 0x8), substr($h, 17, 3), substr($h, 20, 12));
    }

    // ── Delivery ─────────────────────────────────────────────────────────────

    /**
     * Deliver spooled events (oldest first). Safe to call concurrently: one flusher holds the spool
     * lock, the others return at once. Returns counts; never throws.
     *
     * @return array{sent:int,accepted:int,duplicates:int,dead:int,kept:int,error:?string}
     */
    public function flush(int $maxEvents = 500, int $timeoutSeconds = 8): array
    {
        $out = ['sent' => 0, 'accepted' => 0, 'duplicates' => 0, 'dead' => 0, 'kept' => 0, 'error' => null];
        if (!$this->enabled()) {
            return $out;
        }
        try {
            $url = $this->ingestUrl();
            $key = (string) ($this->config['key'] ?? '');
            if ($url === null || $key === '') {
                $out['error'] = 'metering URL or key is not configured';

                return $out;
            }

            $memory = $this->memory;
            $this->memory = [];
            if ($memory !== []) {
                $this->deliver($memory, null, $url, $key, $timeoutSeconds, $out);
            }

            $dir = $this->spoolDir();
            if ($dir === null) {
                return $out;
            }
            $lock = @fopen($dir . '/.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                return $out;
            }
            try {
                $files = glob($dir . '/*.json') ?: [];
                sort($files); // names start with the spool time: oldest first
                $files = array_slice($files, 0, max(1, $maxEvents));
                $now = time();
                $due = [];
                foreach ($files as $file) {
                    $event = json_decode((string) @file_get_contents($file), true);
                    if (!is_array($event)) {
                        $this->bury($file, ['code' => 'unreadable_spool_file']);
                        $out['dead']++;
                        continue;
                    }
                    if ((int) ($event['_next_attempt_at'] ?? 0) > $now) {
                        $out['kept']++;
                        continue;
                    }
                    $due[$file] = $event;
                }
                foreach (array_chunk($due, self::BATCH, true) as $chunk) {
                    if (!$this->deliver(array_values($chunk), array_keys($chunk), $url, $key, $timeoutSeconds, $out)) {
                        break; // Manage unreachable: stop, keep the rest for later
                    }
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
            error_log('[aicountly-metering] flush failed: ' . $e->getMessage());
        }

        return $out;
    }

    /** Spooled and dead-lettered counts, for a product's health endpoint. @return array<string,mixed> */
    public function status(): array
    {
        $dir = $this->spoolDir();
        $pending = $dir === null ? [] : (glob($dir . '/*.json') ?: []);
        $dead    = $dir === null ? [] : (glob($dir . '/dead/*.json') ?: []);
        sort($pending);
        $oldest = $pending === [] ? null : (int) substr(basename($pending[0]), 0, 10);

        return [
            'enabled'            => $this->enabled(),
            'configured'         => $this->ingestUrl() !== null && ($this->config['key'] ?? '') !== '',
            'source'             => $this->config['source'] ?? '',
            'pending'            => count($pending),
            'oldest_pending_age' => $oldest === null ? null : max(0, time() - $oldest),
            'dead_letters'       => count($dead),
            'spool_writable'     => $dir !== null,
            'version'            => self::VERSION,
        ];
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * @param list<array<string,mixed>> $events
     * @param list<string>|null $files spool files, aligned with $events (null: in-memory events)
     * @param array<string,mixed> $out
     */
    private function deliver(array $events, ?array $files, string $url, string $key, int $timeout, array &$out): bool
    {
        $payload = [];
        foreach ($events as $e) {
            $attempt = (int) ($e['_attempts'] ?? 0) + 1;
            unset($e['_attempts'], $e['_next_attempt_at']);
            $e['attempt'] = min($attempt, 1000);
            $payload[] = $e;
        }
        $body = json_encode(['events' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $out['sent'] += count($events);
        [$status, $response] = $this->send($url . '/events/batch', (string) $body, $key, $timeout);
        $json = json_decode($response, true);

        if ($status === 200 && is_array($json['data']['results'] ?? null)) {
            foreach ($json['data']['results'] as $r) {
                $i = (int) ($r['index'] ?? -1);
                if (!isset($events[$i])) {
                    continue;
                }
                $state = (string) ($r['status'] ?? '');
                if ($state === 'accepted' || $state === 'duplicate') {
                    $out[$state === 'accepted' ? 'accepted' : 'duplicates']++;
                    if ($files !== null) {
                        @unlink($files[$i]);
                    }
                    continue;
                }
                $http = (int) ($r['http'] ?? 422);
                // correction_target_unknown: the original may still be on its way (another process's
                // spool); retry with backoff rather than give up.
                if ($http === 503 || $http === 429 || ($r['code'] ?? '') === 'correction_target_unknown') {
                    $this->retryLater($events[$i], $files[$i] ?? null);
                    $out['kept']++;
                    continue;
                }
                error_log('[aicountly-metering] Manage refused event ' . ($events[$i]['event_id'] ?? '?') . ': ' . ($r['code'] ?? '?') . ' ' . ($r['field'] ?? ''));
                if ($files !== null) {
                    $this->bury($files[$i], $r);
                }
                $out['dead']++;
            }

            return true;
        }

        // Whole request refused or Manage unreachable.
        $code = (string) ($json['code'] ?? '');
        $permanent = in_array($status, [400, 401, 403, 413, 422], true);
        foreach ($events as $i => $e) {
            if ($permanent && $status !== 401 && $status !== 403) {
                if ($files !== null) {
                    $this->bury($files[$i], ['code' => $code !== '' ? $code : 'http_' . $status]);
                }
                $out['dead']++;
            } else {
                // 401/403 is a configuration problem (key not issued yet): keep, an operator fixes the key.
                $this->retryLater($e, $files[$i] ?? null);
                $out['kept']++;
            }
        }
        $out['error'] = 'Manage answered ' . ($status === 0 ? 'nothing (unreachable)' : 'HTTP ' . $status) . ($code !== '' ? ' ' . $code : '');
        error_log('[aicountly-metering] delivery deferred: ' . $out['error']);

        return false;
    }

    /** @param array<string,mixed> $event */
    private function retryLater(array $event, ?string $file): void
    {
        $attempts = (int) ($event['_attempts'] ?? 0) + 1;
        $event['_attempts'] = $attempts;
        // 30 s, 1 min, 2 min … capped at 1 hour.
        $event['_next_attempt_at'] = time() + min(3600, 30 * (2 ** min(7, $attempts - 1)));
        if ($file === null) {
            if (!$this->writeSpool($event)) {
                error_log('[aicountly-metering] METERING_EVENT_UNDELIVERED ' . json_encode($event, JSON_UNESCAPED_SLASHES));
            }

            return;
        }
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->bury($file, ['code' => 'max_attempts']);

            return;
        }
        @file_put_contents($file . '.tmp', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @rename($file . '.tmp', $file);
    }

    /** @param array<string,mixed> $reason */
    private function bury(string $file, array $reason): void
    {
        $dead = dirname($file) . '/dead';
        if (!is_dir($dead)) {
            @mkdir($dead, 0700, true);
        }
        $event = json_decode((string) @file_get_contents($file), true);
        $record = ['refused' => array_intersect_key($reason, array_flip(['code', 'message', 'field', 'http'])), 'event' => $event];
        @file_put_contents($dead . '/' . basename($file), json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @unlink($file);
    }

    /** @param array<string,mixed> $event */
    private function spool(array $event): void
    {
        if (!$this->writeSpool($event)) {
            $this->memory[] = $event;
        }
    }

    /** @param array<string,mixed> $event */
    private function writeSpool(array $event): bool
    {
        $dir = $this->spoolDir();
        if ($dir === null) {
            return false;
        }
        // Seconds.microseconds first: files sort in recording order, so an original is always
        // delivered before a correction recorded after it.
        $name = sprintf('%s/%017.6f-%s.json', $dir, microtime(true), $event['event_id']);
        $tmp  = $name . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            return false;
        }

        return @rename($tmp, $name);
    }

    private function spoolDir(): ?string
    {
        $dir = $this->config['spool_dir'] ?? '';
        if ($dir === '') {
            $dir = rtrim(sys_get_temp_dir(), '/') . '/aicountly-metering-' . preg_replace('/[^a-z0-9_-]/', '', $this->config['source'] ?? 'product');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }

        return is_writable($dir) ? rtrim($dir, '/') : null;
    }

    private function scheduleFlush(): void
    {
        if ($this->shutdownRegistered || ($this->config['flush'] ?? 'shutdown') !== 'shutdown' || PHP_SAPI === 'cli') {
            return;
        }
        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            // The user has their answer before Manage is contacted.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            }
            $this->flush(self::BATCH, 4);
        });
    }

    private function ingestUrl(): ?string
    {
        $base = rtrim((string) ($this->config['url'] ?? ''), '/');
        if ($base === '' || preg_match('#^https?://#', $base) !== 1) {
            return null;
        }
        if (!str_ends_with($base, '/api')) {
            $base .= '/api';
        }

        return $base . '/internal/metering';
    }

    /** @return array{0:int,1:string} */
    private function send(string $url, string $body, string $key, int $timeout): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $key,
            'X-Saas-Origin: ' . ($this->config['source'] ?? ''),
            'User-Agent: aicountly-usage-meter/' . self::VERSION,
        ];
        if ($this->transport !== null) {
            return ($this->transport)('POST', $url, $headers, $body, $timeout);
        }
        if (!function_exists('curl_init')) {
            return [0, ''];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => max(2, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$response === false ? 0 : $status, is_string($response) ? $response : ''];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function complete(array $event): array
    {
        $source = (string) ($this->config['source'] ?? '');
        $event['schema_version'] = self::SCHEMA_VERSION;
        $event['occurred_at'] ??= gmdate('Y-m-d\TH:i:s\Z');
        $event['environment'] ??= $this->config['environment'] ?? 'production';
        if (isset($event['idempotency_key']) && is_string($event['idempotency_key'])) {
            $event['idempotency_key'] = self::safeKey($event['idempotency_key']);
            $event['event_id'] ??= self::eventIdFor($source, $event['idempotency_key']);
        }
        foreach ($event as $k => $v) {
            if ($v === null || $v === []) {
                unset($event[$k]);
            }
        }

        return $event;
    }

    /** A cheap local check, so an obviously broken event is reported where it was made. */
    private static function problem(array $e): ?string
    {
        foreach (['idempotency_key', 'category', 'provider', 'usage', 'event_id'] as $k) {
            if (!isset($e[$k]) || $e[$k] === '' || $e[$k] === []) {
                return $k . ' missing';
            }
        }
        if (!is_array($e['usage'])) {
            return 'usage is not a list';
        }
        if (strlen(json_encode($e) ?: '') > 16384) {
            return 'event larger than 16 KiB';
        }

        return null;
    }

    /** @param array<string,mixed> $a */
    private static function common(array $a): array
    {
        return [
            'idempotency_key'     => $a['idempotency_key'] ?? null,
            'event_id'            => $a['event_id'] ?? null,
            'occurred_at'         => $a['occurred_at'] ?? null,
            'origin_app'          => $a['origin_app'] ?? null,
            'origin_feature'      => isset($a['feature']) ? self::feature((string) $a['feature']) : null,
            'company_id'          => self::id($a['company_id'] ?? null),
            'branch_id'           => self::id($a['branch_id'] ?? null),
            'user_uuid'           => isset($a['user_uuid']) && $a['user_uuid'] !== '' ? (string) $a['user_uuid'] : null,
            'provider_request_id' => isset($a['provider_request_id']) && $a['provider_request_id'] !== '' ? substr(preg_replace('/[^\x21-\x7E]/', '', (string) $a['provider_request_id']) ?? '', 0, 200) : null,
            'correlation_id'      => isset($a['correlation_id']) && $a['correlation_id'] !== '' ? substr(preg_replace('/[^A-Za-z0-9._:-]/', '', (string) $a['correlation_id']) ?? '', 0, 128) : null,
            'provider_status'     => isset($a['provider_status']) && $a['provider_status'] !== '' ? substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', (string) $a['provider_status']) ?? '', 0, 48) : null,
            'vendor_cost'         => $a['vendor_cost'] ?? null,
            'metadata'            => $a['metadata'] ?? null,
        ];
    }

    private static function id(mixed $v): ?int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }
        if (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/', $v) === 1) {
            return (int) $v;
        }

        return null;
    }

    private static function feature(string $f): ?string
    {
        $f = strtolower(preg_replace('/[^A-Za-z0-9._:-]/', '_', $f) ?? '');
        $f = ltrim($f, '._:-');

        return $f === '' ? null : substr($f, 0, 96);
    }

    private static function safeKey(string $k): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._:\/@#=+-]/', '_', $k) ?? '';
        // A key longer than the contract allows keeps its prefix and a digest of the whole.
        return strlen($clean) <= 200 ? $clean : substr($clean, 0, 150) . '#' . sha1($k);
    }

    private static function num(mixed $v, int $scale): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        $s = is_float($v) ? sprintf('%.' . $scale . 'F', max(0.0, $v)) : trim((string) $v);
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '' ? '0' : $s;
    }

    /** @param array<string,mixed> $e */
    private static function describe(array $e): string
    {
        return json_encode(array_intersect_key($e, array_flip(['idempotency_key', 'category', 'provider', 'service', 'origin_app'])), JSON_UNESCAPED_SLASHES) ?: '';
    }
}
