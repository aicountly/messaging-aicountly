<?php
declare(strict_types=1);

namespace Aicountly\Api;

use PDOException;
use Throwable;

/**
 * Messaging's database name and database username, from Console.
 *
 * Console's "SaaS Database Details" page is the single place those two values (and the
 * cPanel username) are recorded for every product. This asks it for Messaging's own row:
 *
 *   GET {CONSOLE_API_URL}/database-details/resolve
 *   Authorization: Bearer {CONSOLE_DB_DETAILS_KEY}
 *
 * The key belongs to ONE row (Messaging + one environment), so there is no product or
 * domain parameter to send and it cannot read another product's details. It is not
 * CONSOLE_SERVICE_KEY, which this endpoint rejects.
 *
 * Used by Db::connect(), which is the only place this API (requests, health, bin/ workers,
 * bin/migrate.php) builds a database connection. Nothing else reads DB_NAME or DB_USER.
 *
 * WHAT IS NOT HERE. A database password: Console never stores one, so DB_PASS (and DB_HOST,
 * DB_PORT, DB_SSLMODE) stay in server-php/.env. DB_NAME and DB_USER are not read at all
 * while CONSOLE_API_URL and CONSOLE_DB_DETAILS_KEY are both set; without them (local
 * development, the test suites) the classic DB_NAME / DB_USER apply unchanged.
 *
 * CACHING. A connection is built on nearly every request, so asking Console each time would
 * put a network hop in front of every page and make Console a single point of failure for
 * Messaging. The answer is held in process memory and in a small file in the system temp
 * directory for FRESH_TTL seconds. The file holds the two identifiers and a timestamp: no
 * key, no secret; its name is a hash of URL + key, never the key. If Console cannot be
 * reached (transport error, 5xx, 429) the last good answer is used for up to STALE_TTL
 * seconds and the outage is logged. A definite refusal (401 revoked key, 403 inactive row),
 * an answer that is not the expected JSON, empty values, an unsafe identifier or a key from
 * the wrong environment is NEVER papered over with a cached answer: revoking a key in
 * Console has to actually stop Messaging from using it.
 *
 * Failures are PDOException, like any other "cannot connect" the callers already handle, and
 * no message here ever contains the key.
 */
final class ConsoleDatabaseDetails
{
    private const FRESH_TTL = 300;
    private const STALE_TTL = 604800;      // 7 days
    private const FAILURE_TTL = 15;
    private const CONNECT_TIMEOUT = 2;
    private const TIMEOUT = 4;
    private const MAX_RESPONSE_BYTES = 65536;
    private const IDENTIFIER = '/^[A-Za-z0-9_.$-]{1,128}$/D';
    private const LOG_PREFIX = '[messaging-db] ';

    /** @var array{id: string, name: string, user: string, expires: int}|null */
    private static ?array $memo = null;

    /** @var array{id: string, message: string, category: string, expires: int}|null */
    private static ?array $failure = null;

    /** @var (callable(string, list<string>, int, int, int): array{status: int, body: ?string, error: ?string})|null */
    private static $transport = null;

    private static bool $warnedHalfConfigured = false;

    /** True when this deployment is set up to take its database name/user from Console. */
    public static function isConfigured(): bool
    {
        $url = self::baseUrl();
        $key = self::key();
        if ($url !== '' && $key !== '') {
            return true;
        }
        if (($url !== '' || $key !== '') && !self::$warnedHalfConfigured) {
            // Only one of the two is set: the classic DB_NAME / DB_USER apply. Say so once, because a
            // deployment that meant to use Console would otherwise be talking to the wrong database quietly.
            self::$warnedHalfConfigured = true;
            error_log(self::LOG_PREFIX . 'only one of CONSOLE_API_URL / CONSOLE_DB_DETAILS_KEY is set; the database name and username come from DB_NAME / DB_USER, not from Console.' . self::serviceKeyHint());
        }

        return false;
    }

    /**
     * Why a deployment with no usable database source is "not configured", as a DatabaseDiagnosis category.
     *
     * The two Console settings are a pair. With only one of them, Console is silently never asked and the
     * local DB_NAME / DB_USER decide: that is the state worth naming, because the usual cause is a key put
     * under the wrong variable name (CONSOLE_SERVICE_KEY, which is not this key).
     */
    public static function unconfiguredReason(): string
    {
        return match (true) {
            self::baseUrl() !== '' && self::key() === '' => 'console_key_missing',
            self::baseUrl() === '' && self::key() !== '' => 'console_url_missing',
            default                                      => 'not_configured',
        };
    }

    /** The log line for "there is no database to connect to", naming what is missing. */
    public static function unconfiguredMessage(): string
    {
        return match (self::unconfiguredReason()) {
            'console_key_missing' => 'Database is not configured: CONSOLE_API_URL is set but CONSOLE_DB_DETAILS_KEY is not, and neither are DB_NAME / DB_USER, so Console is never asked.' . self::serviceKeyHint(true),
            'console_url_missing' => 'Database is not configured: CONSOLE_DB_DETAILS_KEY is set but CONSOLE_API_URL is not (it is the Console API base including /api, e.g. https://console.aicountly.org/api), and neither are DB_NAME / DB_USER.',
            default               => 'Database is not configured (set CONSOLE_API_URL and CONSOLE_DB_DETAILS_KEY, or DB_NAME and DB_USER, in api/.env).',
        };
    }

    /**
     * A sentence for the log when CONSOLE_SERVICE_KEY is set but CONSOLE_DB_DETAILS_KEY is not.
     *
     * Presence only: CONSOLE_SERVICE_KEY is never read for its value here and is never sent to Console. It is
     * the estate-wide key, a different credential, and the database-details endpoint rejects it; the key that
     * endpoint wants is the per-row one Console shows once under Generate key (it starts with sdb_).
     */
    private static function serviceKeyHint(bool $leadingSpace = true): string
    {
        if (self::key() !== '' || self::env('CONSOLE_SERVICE_KEY') === '') {
            return '';
        }

        return ($leadingSpace ? ' ' : '') . 'CONSOLE_SERVICE_KEY is set, but it is not used for the database and Console does not accept it there: '
            . 'set the key generated in Console > SaaS Database Details (it starts with sdb_) as CONSOLE_DB_DETAILS_KEY.';
    }

    /**
     * What a connection should use: Console's answer when configured, else DB_NAME / DB_USER.
     *
     * @return array{name: string, user: string}
     * @throws PDOException when Console is configured but cannot give usable details
     */
    public static function connectionIdentity(): array
    {
        if (self::isConfigured()) {
            return self::resolve();
        }

        return [
            'name' => Env::get('DB_NAME'),
            'user' => Env::get('DB_USER'),
        ];
    }

    /**
     * Ask Console again, ignoring every cache (memory and the shared file), then use and cache what it says.
     * For bin/db-check.php: it shows what the next request after the cache expires will get.
     *
     * @return array{name: string, user: string}
     * @throws PDOException when Console cannot give Messaging usable details
     */
    public static function refresh(): array
    {
        self::$memo = null;
        self::$failure = null;
        if (is_file(self::filePath())) {
            @unlink(self::filePath());
        }

        return self::resolve();
    }

    /**
     * @return array{name: string, user: string}
     * @throws PDOException when Console cannot give Messaging usable details
     */
    public static function resolve(): array
    {
        $id = self::cacheId();

        if (self::$memo !== null && self::$memo['id'] === $id && self::$memo['expires'] > time()) {
            return ['name' => self::$memo['name'], 'user' => self::$memo['user']];
        }
        // A failed lookup is remembered briefly so one request that connects more than once does
        // not wait out the timeout each time.
        if (self::$failure !== null && self::$failure['id'] === $id && self::$failure['expires'] > time()) {
            throw new DatabaseConnectionException(self::$failure['message'], self::$failure['category']);
        }

        $cached = self::readFile();
        if ($cached !== null && $cached['fetched_at'] + self::FRESH_TTL > time()) {
            return self::remember($id, $cached['name'], $cached['user'], $cached['fetched_at'] + self::FRESH_TTL);
        }

        $answer = self::fetch();

        if ($answer['ok']) {
            self::writeFile($answer['name'], $answer['user']);

            return self::remember($id, $answer['name'], $answer['user'], time() + self::FRESH_TTL);
        }

        // Console was unreachable or broken (not a refusal): carry on with the last good answer
        // rather than take Messaging down with it.
        if ($answer['retryable'] && $cached !== null && $cached['fetched_at'] + self::STALE_TTL > time()) {
            error_log(self::LOG_PREFIX . 'Console database-details unavailable (' . $answer['message'] . '); using the last details it gave, from '
                . gmdate('c', $cached['fetched_at']) . '.');

            return self::remember($id, $cached['name'], $cached['user'], time() + self::FAILURE_TTL);
        }

        self::$memo = null;
        self::$failure = ['id' => $id, 'message' => $answer['message'], 'category' => $answer['category'], 'expires' => time() + self::FAILURE_TTL];
        throw new DatabaseConnectionException($answer['message'], $answer['category']);
    }

    /**
     * Test seam: replace the HTTP transport (null restores curl). CLI only.
     *
     * @param (callable(string $url, list<string> $headers, int $connectTimeout, int $timeout, int $maxBytes): array{status: int, body: ?string, error: ?string})|null $transport
     *        `error` is null, 'timeout', 'unreachable' or 'too_large'.
     */
    public static function useTransport(?callable $transport): void
    {
        if (PHP_SAPI === 'cli') {
            self::$transport = $transport;
        }
    }

    /** Test seam: forget everything held in memory (and the shared file when asked). CLI only. */
    public static function resetForTesting(bool $includingFile = false): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$memo = null;
        self::$failure = null;
        self::$warnedHalfConfigured = false;
        if ($includingFile && is_file(self::filePath())) {
            @unlink(self::filePath());
        }
    }

    // -----------------------------------------------------------------------

    /**
     * @return array{ok: true, name: string, user: string}|array{ok: false, retryable: bool, message: string, category: string}
     */
    private static function fetch(): array
    {
        $url = self::baseUrl();
        $key = self::key();
        if (preg_match('#^https?://[^\s/?\#]+#i', $url) !== 1) {
            return self::refused('CONSOLE_API_URL must be an http(s) URL (for example https://console.aicountly.org/api).', 'console_config');
        }
        // The key goes into a header: anything but printable ASCII would be a malformed (or injected) header.
        if (preg_match('/^[\x21-\x7e]+$/D', $key) !== 1) {
            return self::refused('CONSOLE_DB_DETAILS_KEY contains characters that cannot be sent as a bearer token.', 'console_config');
        }

        $transport = self::$transport ?? [self::class, 'curlTransport'];
        $response = $transport(
            $url . '/database-details/resolve',
            ['Authorization: Bearer ' . $key, 'Accept: application/json'],
            self::CONNECT_TIMEOUT,
            self::TIMEOUT,
            self::MAX_RESPONSE_BYTES,
        );

        $status = (int) ($response['status'] ?? 0);
        $error = $response['error'] ?? null;
        // The URL is not logged (it is fixed, but the habit is to keep anything near a credential out of
        // the logs); the key never is.
        if ($error === 'too_large') {
            return self::refused('Console answered the database-details request with a response larger than ' . self::MAX_RESPONSE_BYTES . ' bytes.', 'console_unexpected_answer');
        }
        if ($status < 200 || $status > 299) {
            $detail = $status === 0 ? 'Console could not be reached' : 'Console answered HTTP ' . $status;

            return match (true) {
                $status === 401 => self::refused('Console rejected CONSOLE_DB_DETAILS_KEY (revoked, rotated or wrong) — generate a key for this deployment in Console > SaaS Database Details.', 'console_key_rejected'),
                $status === 403 => self::refused('Console reports the database details for this product as inactive — activate the row in Console > SaaS Database Details.', 'console_row_inactive'),
                $status === 0 || $status === 429 || $status >= 500 => ['ok' => false, 'retryable' => true, 'message' => 'Could not fetch database details from Console: ' . $detail . '.', 'category' => 'console_unreachable'],
                default => self::refused('Could not fetch database details from Console: ' . $detail . '.', 'console_unexpected_answer'),
            };
        }

        $body = json_decode((string) ($response['body'] ?? ''), true);
        $data = is_array($body) && ($body['success'] ?? true) !== false && is_array($body['data'] ?? null) ? $body['data'] : null;
        if ($data === null) {
            return self::refused('Console answered the database-details request with something that is not the expected JSON.', 'console_unexpected_answer');
        }

        $name = is_string($data['database_name'] ?? null) ? trim($data['database_name']) : '';
        $user = is_string($data['database_username'] ?? null) ? trim($data['database_username']) : '';
        if ($name === '' || $user === '') {
            return self::refused('Console has no database name / username recorded for Messaging.', 'console_no_database_recorded');
        }
        // Both go into a DSN and a login. A ";" or "=" in a value would add connection parameters rather
        // than name a database, so anything but a plain identifier is refused instead of passed on.
        if (preg_match(self::IDENTIFIER, $name) !== 1 || preg_match(self::IDENTIFIER, $user) !== 1) {
            return self::refused('Console returned a database name or username with characters Messaging will not put in a connection string.', 'console_unexpected_answer');
        }

        // The key is per row, so a Production key in a sandbox .env (or the reverse) would quietly point
        // this deployment at the other environment's database. Same rule as Connect: the check is made
        // only when BOTH sides name an environment (production/prod or sandbox/staging) and they differ.
        // A row that names none, or one this deployment cannot place (local, development, testing), is
        // not a mismatch and is never turned into a refusal.
        $appEnv = self::appEnvironment();
        $rowEnv = self::normalizeEnvironment(is_string($data['environment'] ?? null) ? $data['environment'] : '');
        if ($appEnv !== '' && $rowEnv !== '' && $rowEnv !== $appEnv) {
            return self::refused(sprintf(
                'CONSOLE_DB_DETAILS_KEY belongs to Messaging\'s %s database but this deployment is %s — use the key generated on the matching row in Console, or set AIC_ENVIRONMENT=production|sandbox in server-php/.env if this server is the other environment.',
                $rowEnv,
                $appEnv,
            ), 'console_environment_mismatch');
        }

        return ['ok' => true, 'name' => $name, 'user' => $user];
    }

    /**
     * The environment this API runs as, in Console's vocabulary, from Environment (AIC_ENVIRONMENT, else
     * APP_ENV): 'production', 'sandbox', or '' when it is neither (local, development, unset).
     */
    private static function appEnvironment(): string
    {
        return match (Environment::current()) {
            Environment::PRODUCTION => 'production',
            Environment::SANDBOX    => 'sandbox',
            default                 => '',
        };
    }

    /** The row's environment in the same two words, '' when it names neither. */
    private static function normalizeEnvironment(string $name): string
    {
        return match (strtolower(trim($name))) {
            'production', 'prod' => 'production',
            'sandbox', 'staging' => 'sandbox',
            default              => '',
        };
    }

    /** @return array{status: int, body: ?string, error: ?string} */
    private static function curlTransport(string $url, array $headers, int $connectTimeout, int $timeout, int $maxBytes): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => null, 'error' => 'unreachable'];
        }
        $body = '';
        $tooLarge = false;
        $options = [
            CURLOPT_HTTPGET        => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_FOLLOWLOCATION => false,   // a redirect is an answer, not somewhere to send the key
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0;   // abort the transfer
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($tooLarge) {
            return ['status' => $status, 'body' => null, 'error' => 'too_large'];
        }
        if ($errno !== 0) {
            return ['status' => 0, 'body' => null, 'error' => $errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unreachable'];
        }

        return ['status' => $status, 'body' => $body, 'error' => null];
    }

    /** @return array{ok: false, retryable: bool, message: string, category: string} */
    private static function refused(string $message, string $category): array
    {
        return ['ok' => false, 'retryable' => false, 'message' => $message, 'category' => $category];
    }

    /** @return array{name: string, user: string} */
    private static function remember(string $id, string $name, string $user, int $expires): array
    {
        self::$memo = ['id' => $id, 'name' => $name, 'user' => $user, 'expires' => $expires];
        self::$failure = null;

        return ['name' => $name, 'user' => $user];
    }

    private static function env(string $name): string
    {
        return trim(Env::get($name));
    }

    private static function baseUrl(): string
    {
        return rtrim(self::env('CONSOLE_API_URL'), '/');
    }

    private static function key(): string
    {
        return self::env('CONSOLE_DB_DETAILS_KEY');
    }

    /** Names the (URL, key) pair without containing the key: a rotated key or another Console never reads an old answer. */
    private static function cacheId(): string
    {
        return substr(hash('sha256', self::baseUrl() . '|' . self::key()), 0, 32);
    }

    private static function filePath(): string
    {
        return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'messaging-console-db-' . self::cacheId() . '.json';
    }

    /** @return array{name: string, user: string, fetched_at: int}|null */
    private static function readFile(): ?array
    {
        $path = self::filePath();
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            return null;
        }
        // Another account sharing /tmp could have planted a file under this name; only ours is believed.
        if (function_exists('posix_geteuid') && @fileowner($path) !== posix_geteuid()) {
            return null;
        }
        $raw = @file_get_contents($path, false, null, 0, 4096);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (
            !is_array($data)
            || !is_string($data['name'] ?? null) || !is_string($data['user'] ?? null)
            || !is_int($data['fetched_at'] ?? null)
            // Same rule as a fresh answer: the file is never a way around the identifier check.
            || preg_match(self::IDENTIFIER, $data['name']) !== 1 || preg_match(self::IDENTIFIER, $data['user']) !== 1
            || $data['fetched_at'] > time() + 60
        ) {
            return null;
        }

        return ['name' => $data['name'], 'user' => $data['user'], 'fetched_at' => $data['fetched_at']];
    }

    private static function writeFile(string $name, string $user): void
    {
        $path = self::filePath();
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $payload = json_encode(['name' => $name, 'user' => $user, 'fetched_at' => time()]);
        if ($payload === false) {
            return;
        }
        // Best effort: an unwritable temp dir only costs a Console call per request, it must never fail
        // the request. Owner-only from the first byte (umask), created exclusively (no symlink followed),
        // and moved into place atomically so a reader never sees half a file.
        $previous = umask(0077);
        try {
            $fh = @fopen($tmp, 'xb');
            if ($fh === false) {
                return;
            }
            $written = fwrite($fh, $payload);
            fclose($fh);
            @chmod($tmp, 0600);
            if ($written !== strlen($payload) || !@rename($tmp, $path)) {
                @unlink($tmp);
            }
        } catch (Throwable) {
            // A failing disk costs a Console call next time; it never fails the request.
        } finally {
            umask($previous);
        }
    }
}
