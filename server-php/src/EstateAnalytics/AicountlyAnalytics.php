<?php
declare(strict_types=1);

namespace Aicountly\Analytics;

/**
 * AICOUNTLY analytics client — the one way a product reports behaviour to
 * analytics.aicountly.org (docs/ in analytics-aicountly). Version: see VERSION next to this file.
 *
 * COPY, DON'T EDIT: this file lives in analytics-aicountly "sdk/php/". Products copy it unchanged
 * (no dependencies: PHP 8.1+, ext-curl, ext-json), so a fix is made once and copied out. It is the
 * deliberate twin of Manage's AicountlyUsageMeter: same spool, same flush, same dead letters, same
 * failure posture. If you know that file, you know this one.
 *
 * Behaviour, in one paragraph: track() never throws and never blocks the user. It writes the event
 * to a local spool directory (one small file per event, written atomically) and returns. After the
 * response has been sent (fastcgi_finish_request / litespeed_finish_request) one batch is delivered
 * with a short timeout; anything not delivered stays in the spool and is sent by the next request
 * or by the cron drain (`php analytics-flush.php`). Delivery is at-least-once; Analytics makes it
 * exactly-once with the idempotency key. Events refused as invalid move to spool/dead/ with the
 * reason and are logged — never silently dropped.
 *
 * WHAT MUST NEVER BE SENT: names, e-mail addresses, phone numbers, amounts, document numbers,
 * GSTINs, PANs, search terms, filenames, message bodies — any content at all. Analytics records
 * *that* an invoice was created, never what was in it. The server refuses a property key that
 * merely looks sensitive, and this client refuses the same keys locally so the mistake is caught
 * in development rather than as a rejection in production.
 *
 * ANALYTICS MUST NEVER CHANGE WHAT A USER SEES. Nothing here can slow, block or fail a request.
 *
 * Configuration (environment, or the $config array of fromEnv()):
 *   AICOUNTLY_ANALYTICS_ENABLED      1 to send. Default 0 (events are not even spooled when 0).
 *   AICOUNTLY_ANALYTICS_URL          https://analytics.aicountly.org/api
 *   AICOUNTLY_ANALYTICS_KEY          this product's labelled Analytics service key
 *   AICOUNTLY_ANALYTICS_SOURCE       this product's catalog id (books, sales, my, …)
 *   AICOUNTLY_ANALYTICS_ENVIRONMENT  production | sandbox | development (default production)
 *   AICOUNTLY_ANALYTICS_SPOOL_DIR    writable directory, NOT under public_html
 *   AICOUNTLY_ANALYTICS_FLUSH        shutdown (default) | cron
 */
final class Analytics
{
    public const VERSION        = '1.0.0';
    public const SCHEMA_VERSION = '1';
    private const BATCH         = 200;
    private const MAX_ATTEMPTS  = 200;

    /** Mirrors EventContract::SENSITIVE_PART on the server. Kept in step by sdk/php/README. */
    private const SENSITIVE_PART = [
        'email', 'mail', 'phone', 'mobile', 'address', 'password', 'passwd', 'secret',
        'credential', 'aadhaar', 'aadhar', 'gstin', 'prompt', 'salary', 'invoice_no',
        'filename', 'file_name', 'username', 'user_id', 'uuid', 'recipient', 'content',
        'description', 'remark', 'comment',
    ];
    /** Mirrors EventContract::SENSITIVE_WORD: whole `_`-separated tokens only. */
    private const SENSITIVE_WORD = [
        'name', 'ip', 'pan', 'otp', 'url', 'card', 'text', 'body', 'note', 'cost', 'amount',
        'price', 'balance', 'account', 'ifsc', 'query', 'search', 'message', 'subject', 'title',
        'token', 'contact', 'gst', 'dob', 'pin',
    ];

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

        return new self($overrides + [
            'enabled'     => $first(['AICOUNTLY_ANALYTICS_ENABLED']),
            'url'         => $first(['AICOUNTLY_ANALYTICS_URL']),
            'key'         => $first(['AICOUNTLY_ANALYTICS_KEY']),
            'source'      => strtolower($first(['AICOUNTLY_ANALYTICS_SOURCE', 'APP_PRODUCT_KEY'])),
            'environment' => $first(['AICOUNTLY_ANALYTICS_ENVIRONMENT']) ?: 'production',
            'spool_dir'   => $first(['AICOUNTLY_ANALYTICS_SPOOL_DIR']),
            'flush'       => $first(['AICOUNTLY_ANALYTICS_FLUSH']) ?: 'shutdown',
        ]);
    }

    /** One client per process, built from the environment on first use. */
    public static function shared(?callable $env = null): self
    {
        return self::$shared ??= self::fromEnv($env);
    }

    /** Tests: replace or clear the shared client. */
    public static function setShared(?self $client): void
    {
        self::$shared = $client;
    }

    public function enabled(): bool
    {
        return in_array(strtolower($this->config['enabled'] ?? ''), ['1', 'true', 'on', 'yes'], true);
    }

    // ── Recording ────────────────────────────────────────────────────────────

    /**
     * Record one behavioural event. Returns its event_id, or null when analytics is off or the
     * event was unusable (logged). NEVER THROWS.
     *
     * The only required arguments are the event name and who it was for. Everything else is
     * optional. `idempotency_key` is derived when not given, from the event, the actor and the
     * second — enough that an accidental double-call in one request collapses to one event, and
     * a genuine second occurrence a second later does not.
     *
     * @param string $eventName must be registered in the Analytics tracking plan (docs/04)
     * @param array{
     *   company_id?:?int, branch_id?:?int, user_uuid?:?string, user_kind?:string,
     *   properties?:array<string,scalar>, page_path?:?string, session_id?:?string,
     *   origin_surface?:string, outcome?:string, duration_ms?:?int, referrer_host?:?string,
     *   client?:array<string,mixed>, origin_app?:?string, occurred_at?:?string,
     *   idempotency_key?:?string
     * } $a
     */
    public function track(string $eventName, array $a = []): ?string
    {
        if (!$this->enabled()) {
            return null;
        }
        try {
            $source = (string) ($this->config['source'] ?? '');
            $name   = strtolower(trim($eventName));

            $key = isset($a['idempotency_key']) && $a['idempotency_key'] !== ''
                ? self::safeKey((string) $a['idempotency_key'])
                : self::safeKey(sprintf('%s:%s:%s:%s:%d', $source, $name,
                    (string) ($a['company_id'] ?? '-'), (string) ($a['user_uuid'] ?? '-'), time()));

            [$properties, $badKey] = $this->properties($a['properties'] ?? []);
            if ($badKey !== null) {
                // Caught here so it fails in development, where the developer can see it, rather
                // than as a silent rejection in production.
                error_log("[aicountly-analytics] property '{$badKey}' looks sensitive and was refused; event '{$name}' not recorded");

                return null;
            }

            $event = array_filter([
                'schema_version'  => self::SCHEMA_VERSION,
                'event_id'        => self::eventIdFor($source, $key),
                'idempotency_key' => $key,
                'occurred_at'     => $a['occurred_at'] ?? gmdate('Y-m-d\TH:i:s\Z'),
                'environment'     => $this->config['environment'] ?? 'production',
                'event_name'      => $name,
                'origin_app'      => $a['origin_app'] ?? $source,
                'origin_surface'  => $a['origin_surface'] ?? (PHP_SAPI === 'cli' ? 'cron' : 'server'),
                'company_id'      => self::id($a['company_id'] ?? null),
                'branch_id'       => self::id($a['branch_id'] ?? null),
                'user_uuid'       => isset($a['user_uuid']) && $a['user_uuid'] !== '' ? (string) $a['user_uuid'] : null,
                'user_kind'       => $a['user_kind'] ?? 'customer',
                'session_id'      => isset($a['session_id']) && $a['session_id'] !== ''
                                     ? substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $a['session_id']) ?? '', 0, 64)
                                     : null,
                'page_path'       => isset($a['page_path']) ? self::maskPath((string) $a['page_path']) : null,
                'referrer_host'   => isset($a['referrer_host']) && $a['referrer_host'] !== ''
                                     ? substr(strtolower(preg_replace('/[^A-Za-z0-9.-]/', '', (string) $a['referrer_host']) ?? ''), 0, 120)
                                     : null,
                'outcome'         => $a['outcome'] ?? 'success',
                'duration_ms'     => isset($a['duration_ms']) ? max(0, (int) $a['duration_ms']) : null,
                'properties'      => $properties,
                'client'          => $a['client'] ?? null,
            ], static fn ($v) => $v !== null && $v !== []);

            $problem = self::problem($event);
            if ($problem !== null) {
                error_log('[aicountly-analytics] event not recorded (' . $problem . '): ' . $name);

                return null;
            }

            $this->spool($event);
            $this->scheduleFlush();

            return (string) $event['event_id'];
        } catch (\Throwable $e) {
            error_log('[aicountly-analytics] track failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Record a batch of events that arrived together — what the browser collect endpoint uses.
     * Each entry is ['event' => name, ...the track() options]. Returns how many were spooled.
     *
     * @param list<array<string,mixed>> $events
     */
    public function trackMany(array $events, array $common = []): int
    {
        $n = 0;
        foreach ($events as $e) {
            if (!is_array($e) || !isset($e['event'])) {
                continue;
            }
            $name = (string) $e['event'];
            unset($e['event']);
            // $common wins: it carries the identity the backend verified, which a browser payload
            // must never be able to override. The operand order is the whole of that guarantee —
            // PHP's `+` keeps the LEFT array's keys, so `$common + $e` lets the verified
            // company_id, user_uuid and user_kind stand and `$e + $common` would let the browser
            // forge all three. Covered by trackManyTest.php.
            if ($this->track($name, $common + $e) !== null) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * The event_id for an idempotency key: deterministic (UUIDv5-style over source + key), so the
     * same occurrence always maps to the same event, across retries and processes.
     */
    public static function eventIdFor(string $source, string $idempotencyKey): string
    {
        $h = sha1('aicountly-analytics:' . $source . ':' . $idempotencyKey);

        return sprintf('%s-%s-5%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3),
            dechex((hexdec($h[16]) & 0x3) | 0x8), substr($h, 17, 3), substr($h, 20, 12));
    }

    /** The masked form of a route: every identifier-looking segment becomes ":id". */
    public static function maskPath(string $path): string
    {
        $clean = (string) preg_replace('/[?#].*$/', '', trim($path));
        if ($clean === '') {
            return '/';
        }
        $clean = '/' . ltrim($clean, '/');

        return implode('/', array_map(static function (string $s): string {
            if ($s === '' || $s === ':id') {
                return $s;
            }
            if (str_contains($s, '@') || preg_match('/^\d+$/', $s) === 1) {
                return ':id';
            }
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s) === 1) {
                return ':id';
            }
            if (preg_match('/^[0-9a-f]{16,}$/i', $s) === 1) {
                return ':id';
            }
            if (strlen($s) >= 16 && preg_match('/^[A-Za-z0-9+\/=_-]+$/', $s) === 1
                && (preg_match('/\d/', $s) === 1
                    || (preg_match('/[a-z]/', $s) === 1 && preg_match('/[A-Z]/', $s) === 1))) {
                return ':id';
            }

            return $s;
        }, explode('/', $clean)));
    }

    /** True when a property key would be refused. Same rule as the server's EventContract. */
    public static function isSensitiveKey(string $key): bool
    {
        $k = strtolower($key);
        foreach (self::SENSITIVE_PART as $w) {
            if (str_contains($k, $w)) {
                return true;
            }
        }

        return in_array(true, array_map(
            static fn (string $t): bool => in_array($t, self::SENSITIVE_WORD, true),
            explode('_', $k)
        ), true);
    }

    // ── Delivery ─────────────────────────────────────────────────────────────

    /**
     * Deliver spooled events (oldest first). Safe to call concurrently: one flusher holds the
     * spool lock, the others return at once. Returns counts; never throws.
     *
     * @return array{sent:int,accepted:int,duplicates:int,dead:int,kept:int,error:?string}
     */
    public function flush(int $maxEvents = 1000, int $timeoutSeconds = 8): array
    {
        $out = ['sent' => 0, 'accepted' => 0, 'duplicates' => 0, 'dead' => 0, 'kept' => 0, 'error' => null];
        if (!$this->enabled()) {
            return $out;
        }
        try {
            $url = $this->ingestUrl();
            $key = (string) ($this->config['key'] ?? '');
            if ($url === null || $key === '') {
                $out['error'] = 'analytics URL or key is not configured';

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
                        break; // Analytics unreachable: stop, keep the rest for later
                    }
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage();
            error_log('[aicountly-analytics] flush failed: ' . $e->getMessage());
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
                if ($http === 503 || $http === 429) {
                    $this->retryLater($events[$i], $files[$i] ?? null);
                    $out['kept']++;
                    continue;
                }
                error_log('[aicountly-analytics] refused ' . ($events[$i]['event_name'] ?? '?')
                    . ': ' . ($r['code'] ?? '?') . ' ' . ($r['field'] ?? ''));
                if ($files !== null) {
                    $this->bury($files[$i], $r);
                }
                $out['dead']++;
            }

            return true;
        }

        $code = (string) ($json['code'] ?? '');
        $permanent = in_array($status, [400, 413, 422], true);
        foreach ($events as $i => $e) {
            if ($permanent) {
                if ($files !== null) {
                    $this->bury($files[$i], ['code' => $code !== '' ? $code : 'http_' . $status]);
                }
                $out['dead']++;
            } else {
                // 401/403 is a configuration problem (key not issued yet): keep it, an operator
                // fixes the key and the backlog delivers itself.
                $this->retryLater($e, $files[$i] ?? null);
                $out['kept']++;
            }
        }
        $out['error'] = 'Analytics answered ' . ($status === 0 ? 'nothing (unreachable)' : 'HTTP ' . $status)
            . ($code !== '' ? ' ' . $code : '');

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
                error_log('[aicountly-analytics] ANALYTICS_EVENT_UNDELIVERED ' . json_encode($event, JSON_UNESCAPED_SLASHES));
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
            // Bounded: analytics must not grow a process's memory without limit.
            if (count($this->memory) < 500) {
                $this->memory[] = $event;
            }
        }
    }

    /** @param array<string,mixed> $event */
    private function writeSpool(array $event): bool
    {
        $dir = $this->spoolDir();
        if ($dir === null) {
            return false;
        }
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
            $dir = rtrim(sys_get_temp_dir(), '/') . '/aicountly-analytics-'
                 . preg_replace('/[^a-z0-9_-]/', '', $this->config['source'] ?? 'product');
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
            // The user has their answer before Analytics is contacted.
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

        return $base . '/internal/analytics';
    }

    /** @return array{0:int,1:string} */
    private function send(string $url, string $body, string $key, int $timeout): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $key,
            'X-Saas-Origin: ' . ($this->config['source'] ?? ''),
            'User-Agent: aicountly-analytics/' . self::VERSION,
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
     * @param mixed $raw
     * @return array{0:array<string,scalar>,1:?string} [properties, first refused key]
     */
    private function properties(mixed $raw): array
    {
        if (!is_array($raw) || $raw === []) {
            return [[], null];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            $key = strtolower((string) $key);
            if (self::isSensitiveKey($key)) {
                return [[], $key];
            }
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
                continue;
            }
            if (is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
                $out[$key] = $value;
            } elseif (is_string($value)) {
                $out[$key] = substr($value, 0, 200);
            }
            // Arrays and objects are dropped: they are how a document ends up in analytics.
            if (count($out) >= 25) {
                break;
            }
        }

        return [$out, null];
    }

    /** A cheap local check, so an obviously broken event is reported where it was made. */
    private static function problem(array $e): ?string
    {
        foreach (['idempotency_key', 'event_name', 'event_id'] as $k) {
            if (!isset($e[$k]) || $e[$k] === '') {
                return $k . ' missing';
            }
        }
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', (string) $e['event_name']) !== 1) {
            return 'event_name must be <object>.<verb>';
        }
        if (strlen(json_encode($e) ?: '') > 16384) {
            return 'event larger than 16 KiB';
        }

        return null;
    }

    private static function id(mixed $v): ?int
    {
        if (is_int($v) && $v > 0) {
            return $v;
        }
        if (is_string($v) && preg_match('/^[1-9][0-9]{0,18}$/', $v) === 1) {
            return (int) $v;
        }

        return null;
    }

    private static function safeKey(string $k): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._:\/@#=+-]/', '_', $k) ?? '';

        return strlen($clean) <= 200 ? $clean : substr($clean, 0, 150) . '#' . sha1($k);
    }
}
