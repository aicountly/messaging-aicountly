<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Request parsing and JSON responses.
 *
 * The envelopes match the rest of the AICOUNTLY fleet so a client written
 * against Books or Appointments reads this API without a second set of rules:
 *
 *   single  {"data": {...}}
 *   list    {"data": [...], "meta": {"total", "limit", "offset"}}
 *   error   {"error": {"code", "message", "details"}, "message"}
 *
 * Every response is `Cache-Control: no-store`. This API serves conversation
 * content and, through the context endpoints, balances and order values read
 * live from their owning products. None of that may come to rest in a shared
 * cache, a proxy, or a service worker — see docs/DATA_OWNERSHIP.md.
 */
final class Http
{
    /** Largest page a list endpoint will serve, however large a client asks for. */
    public const MAX_LIMIT = 200;

    private static ?array $body = null;

    /**
     * Send the response and stop.
     *
     * Under CLI it throws ResponseSent instead of exiting, so the test suite can
     * assert on what a real controller produced. The web path is unchanged.
     *
     * @param array<string, mixed> $payload
     */
    public static function json(int $status, array $payload): never
    {
        if (PHP_SAPI === 'cli') {
            throw new ResponseSent($status, $payload);
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        // The id this request's audit rows and log lines carry; the SPA quotes it on a 5xx.
        header('X-Request-Id: ' . self::requestId());
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public static function data(array $data, int $status = 200): never
    {
        self::json($status, ['data' => $data]);
    }

    /**
     * @param list<mixed>          $rows
     * @param array<string, mixed> $extra merged into meta (counts, totals, filters echoed back)
     */
    public static function list(array $rows, int $total, int $limit, int $offset, array $extra = []): never
    {
        self::json(200, [
            'data' => $rows,
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset] + $extra,
        ]);
    }

    /**
     * A CSV download. Under CLI it throws ResponseSent carrying the file, as json() does.
     */
    public static function csv(string $filename, string $content, bool $truncated = false, int $rows = 0): never
    {
        if (PHP_SAPI === 'cli') {
            throw new ResponseSent(200, ['csv' => $content, 'filename' => $filename, 'truncated' => $truncated, 'rows' => $rows]);
        }

        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header('X-Export-Truncated: ' . ($truncated ? 'true' : 'false'));
        echo "\xEF\xBB\xBF" . $content;
        exit;
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $code, string $message, array $details = []): never
    {
        self::json($status, [
            'error'   => ['code' => $code, 'message' => $message, 'details' => $details],
            'message' => $message,
        ]);
    }

    /** @param array<string, mixed> $details */
    public static function validationFailed(string $message, array $details = []): never
    {
        self::error(422, 'validation_failed', $message, $details);
    }

    public static function notFound(string $message = 'Not found.'): never
    {
        self::error(404, 'not_found', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do that.'): never
    {
        self::error(403, 'forbidden', $message);
    }

    /**
     * my.aicountly could not say whether the session is live (down, slow, 5xx, unreadable). Not
     * "sign in again": 503 + Retry-After, so the SPA keeps the session and the person's work and
     * retries (E8-ID; the fleet's MNY-18 rule).
     */
    public static function authUnavailable(int $retryAfter = 5): never
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('Retry-After: ' . max(1, $retryAfter));
        }
        $message = 'Sign-in could not be checked right now. Please retry in a moment.';
        self::json(503, [
            'error'   => ['code' => 'auth_unavailable', 'message' => $message, 'details' => ['retryable' => true, 'retry_after' => max(1, $retryAfter)]],
            'message' => $message,
        ]);
    }

    public static function unauthorized(string $message = 'Sign in again to continue.'): never
    {
        self::error(401, 'unauthorized', $message);
    }

    /** @param array<string, mixed> $details */
    public static function conflict(string $message, array $details = []): never
    {
        self::error(409, 'conflict', $message, $details);
    }

    /**
     * The decoded JSON request body, or an empty array.
     *
     * Parsed once: reading php://input twice returns nothing the second time on
     * some SAPIs, which turns a perfectly good request into a validation error.
     *
     * @return array<string, mixed>
     */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return self::$body = [];
        }
        $decoded = json_decode($raw, true);

        return self::$body = is_array($decoded) ? $decoded : [];
    }

    /** CLI only. Lets a test call a controller that reads a JSON body. */
    public static function setBodyForTesting(?array $body): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$body = $body;
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function header(string $name): string
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        $value = $_SERVER[$key] ?? $_SERVER['REDIRECT_' . $key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /** The raw request body, for provider webhook signature verification. */
    public static function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    /** Query parameter, falling back to the JSON body so POST callers need not duplicate context in the URL. */
    public static function param(string $name, ?string $default = null): ?string
    {
        if (isset($_GET[$name]) && is_scalar($_GET[$name])) {
            return trim((string) $_GET[$name]);
        }
        $body = self::body();
        if (isset($body[$name]) && is_scalar($body[$name])) {
            return trim((string) $body[$name]);
        }

        return $default;
    }

    public static function intParam(string $name, ?int $default = null): ?int
    {
        $raw = self::param($name);

        return ($raw === null || $raw === '') ? $default : (int) $raw;
    }

    public static function boolParam(string $name, bool $default = false): bool
    {
        $raw = self::param($name);
        if ($raw === null || $raw === '') {
            return $default;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * limit / offset / sort / order / q, clamped.
     *
     * `limit` is bounded at MAX_LIMIT rather than honoured: an unbounded page is
     * a denial-of-service one query string long.
     *
     * @param list<string> $sortable columns a caller may sort by; anything else falls back to the first
     * @return array{limit:int, offset:int, sort:string, order:string, q:string}
     */
    public static function listParams(array $sortable, string $defaultSort = '', string $defaultOrder = 'desc'): array
    {
        $limit = (int) (self::param('limit') ?? 50);
        $limit = max(1, min(self::MAX_LIMIT, $limit === 0 ? 50 : $limit));

        $offset = max(0, (int) (self::param('offset') ?? 0));
        $page = (int) (self::param('page') ?? 0);
        if ($page > 1 && $offset === 0) {
            $offset = ($page - 1) * $limit;
        }

        $sort = (string) (self::param('sort') ?? '');
        if (!in_array($sort, $sortable, true)) {
            $sort = $defaultSort !== '' ? $defaultSort : ($sortable[0] ?? 'id');
        }

        $order = strtolower((string) (self::param('order') ?? $defaultOrder));
        $order = $order === 'asc' ? 'ASC' : 'DESC';

        return [
            'limit'  => $limit,
            'offset' => $offset,
            'sort'   => $sort,
            'order'  => $order,
            'q'      => trim((string) (self::param('q') ?? '')),
        ];
    }

    /**
     * The client's idempotency key for a write, or '' when none was sent.
     *
     * Bounded and character-restricted before it reaches a database column or a
     * provider: a key is echoed back in error messages and stored, so it is
     * treated as untrusted input like anything else from a request.
     */
    public static function idempotencyKey(): string
    {
        $key = self::header('Idempotency-Key');
        if ($key === '' || strlen($key) < 8 || strlen($key) > 200) {
            return '';
        }

        return preg_match('/^[A-Za-z0-9._:-]+$/', $key) === 1 ? $key : '';
    }

    /** This request's reference (Audit::requestId: the caller's X-Request-Id when it is a plain token, else 32-hex). */
    public static function requestId(): string
    {
        return Audit::requestId();
    }

    /**
     * Answer a failure nothing else handled: one log line with the reference, the exception's
     * class, message and file:line and the request's method and path, and a JSON answer with a
     * human message, a code and the same reference. The exception's own text never goes to the
     * caller. index.php's global catch is the caller ($logMessage: what to log instead of the
     * exception's message, e.g. only the first line of a database error).
     *
     * @param array<string, mixed> $details
     */
    public static function uncaught(\Throwable $e, int $status = 500, string $code = 'server_error', string $message = '', array $details = [], ?string $logMessage = null): never
    {
        $rid = self::requestId();
        error_log(sprintf(
            '[messaging] uncaught %s [%s] %s %s: %s @ %s:%d',
            $e::class,
            $rid,
            self::logMethod(),
            self::logPath(),
            str_replace(["\r", "\n"], ' ', $logMessage ?? $e->getMessage()),
            $e->getFile(),
            $e->getLine(),
        ));
        if ($message === '') {
            // An uncaught failure can come after work was done, so a write says to check first.
            $message = in_array(self::logMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
                ? 'Messaging could not complete this request. Try again, and quote reference ' . $rid . ' if it keeps failing.'
                : 'Messaging could not complete this request, so it is not known whether the change was saved. Reload to check before trying again, and quote reference ' . $rid . ' if it keeps failing.';
        } else {
            $message .= ' Reference: ' . $rid . '.';
        }
        self::json($status, [
            'error'      => ['code' => $code, 'message' => $message, 'details' => $details, 'request_id' => $rid],
            'message'    => $message,
            'request_id' => $rid,
        ]);
    }

    /**
     * register_shutdown_function target (index.php): a fatal error is not a Throwable, so the
     * global catch never sees it and PHP would answer an empty 500. When nothing has been sent
     * yet, answer as uncaught() does.
     */
    public static function answerFatal(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        $rid = self::requestId();
        error_log(sprintf(
            '[messaging] fatal [%s] %s %s: %s @ %s:%d',
            $rid,
            self::logMethod(),
            self::logPath(),
            str_replace(["\r", "\n"], ' ', (string) $error['message']),
            (string) $error['file'],
            (int) $error['line'],
        ));
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $message = 'Messaging could not complete this request. Try again, and quote reference ' . $rid . ' if it keeps failing.';
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Request-Id: ' . $rid);
        echo json_encode([
            'error'      => ['code' => 'server_error', 'message' => $message, 'details' => [], 'request_id' => $rid],
            'message'    => $message,
            'request_id' => $rid,
        ], JSON_UNESCAPED_SLASHES);
    }

    private static function logMethod(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? '';

        return is_string($method) && $method !== '' ? strtoupper(substr($method, 0, 16)) : '-';
    }

    private static function logPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = is_string($uri) ? (string) parse_url($uri, PHP_URL_PATH) : '';

        // The path only: a query string can carry a token.
        return $path !== '' ? substr((string) preg_replace('/[^\x21-\x7E]/', '?', $path), 0, 300) : '-';
    }
}
