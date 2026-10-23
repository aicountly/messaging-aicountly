<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Clients\ManageClient;

/**
 * Relays the browser's company-registry calls to AICOUNTLY Manage: /api/manage/v1/* on this host
 * becomes {Manage}/api/v1/* on the server.
 *
 * Why it exists: the production hosting (cPanel with Imunify360's WebShield splash screen) answers
 * browser requests from IP ranges it distrusts — common Indian CGNAT broadband among them — with a
 * challenge page that carries no CORS headers. A call from messaging.aicountly.com straight to
 * manage.aicountly.com then fails in the browser with no response at all, and Users & Access, My
 * workspaces and the company picker show "Manage could not be reached". A call to this app's own
 * /api is same-origin and passes, so the browser calls here and this server calls Manage.
 *
 * What it forwards: exactly the calls the shared client (web/src/company-access/companyRegistryClient.ts)
 * makes, each with its own method — tests/manage_relay.php fails if the client gains one that is
 * not listed here. Anything else is a 404. Only the person's own Authorization and the few headers
 * Manage reads on these calls are passed on; never cookies, never a service key or actor header a
 * browser might send. Manage therefore decides every call exactly as it would have from the
 * browser: this relay grants nothing. Where Manage lives comes from this server's configuration
 * (ManageClient: MANAGE_API_BASE, else the environment's Manage), never from the request.
 */
final class ManageRelay
{
    /** A grant reference or a person id in a registry path (`<uuid>:<app>`), never `.` / `..`. */
    private const REF = '[A-Za-z0-9_:@-][A-Za-z0-9_:.@-]*';

    /** @var array<string, list<string>> method => registry path patterns, from companyRegistryClient.ts */
    private const ROUTES = [
        'GET' => [
            '#^v1/applications$#',
            '#^v1/company-directory$#',
            '#^v1/companies/\d+/(summary|access|permissions|access-audit|access-requests)$#',
            '#^v1/access-requests$#',
            '#^v1/workspaces$#',
            '#^v1/workspaces/\d+/(members|member-suggestions)$#',
            // company-access 1.2.0 (CONTRACTS §1/§2/§3.5): the role catalogue, where a company may be
            // created, a company's plan and the person's own, and what waits on them in Manage.
            '#^v1/app-roles$#',
            '#^v1/company-creation-targets$#',
            '#^v1/companies/\d+/plan$#',
            '#^v1/me/(plan|pending)$#',
        ],
        'POST' => [
            '#^v1/companies/duplicate-check$#',
            '#^v1/companies/\d+/(permissions|access-requests)$#',
            '#^v1/access-requests/\d+/(approve|reject|cancel)$#',
            '#^v1/workspaces/\d+/(members|accept|decline|exit)$#',
            '#^v1/companies/\d+/creator-access/retry$#',
        ],
        'PUT' => [
            '#^v1/companies/\d+/visibility$#',
        ],
        'PATCH' => [
            '#^v1/companies/\d+/permissions/' . self::REF . '$#',
            // 1.1/1.2: the owner renames their workspace and edits a member's sharing settings.
            '#^v1/workspaces/\d+$#',
            '#^v1/workspaces/\d+/members/' . self::REF . '$#',
        ],
        'DELETE' => [
            '#^v1/companies/\d+/permissions/' . self::REF . '$#',
            '#^v1/workspaces/\d+/members/' . self::REF . '$#',
        ],
    ];

    /** Request headers Manage reads on a registry call, forwarded as the browser sent them. Nothing else is. */
    public const FORWARDED_HEADERS = ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Correlation-Id'];

    private const CONNECT_TIMEOUT_SECONDS = 8;
    private const READ_TIMEOUT_SECONDS = 20;
    /** A grant seats the person in the product on Manage's side before it answers: writes get longer. */
    private const WRITE_TIMEOUT_SECONDS = 45;

    /** Whether `$method $path` (decoded, without the leading `manage/`) is a call the shared client makes. */
    public static function isRelayed(string $method, string $path): bool
    {
        foreach (self::ROUTES[strtoupper($method)] ?? [] as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The registry path's segments, decoded, from the raw request path (`manage/v1/...`, still
     * percent-encoded). Null when any segment is empty, `.`/`..` or carries a character no registry
     * path uses — so an encoded slash or a traversal can never reach Manage as a path level.
     *
     * @return list<string>|null
     */
    public static function segments(string $rawPath): ?array
    {
        $parts = explode('/', trim($rawPath, '/'));
        if (strtolower((string) array_shift($parts)) !== 'manage' || $parts === []) {
            return null;
        }
        $segments = [];
        foreach ($parts as $part) {
            $segment = rawurldecode($part);
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9_:.@-]+$/', $segment) !== 1) {
                return null;
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * Where the call goes: Manage's API root from configuration, each segment encoded again, and
     * the browser's query string as it was sent. Null when the call is not relayed.
     */
    public static function upstreamUrl(string $method, string $rawPath, string $query, string $apiRoot): ?string
    {
        $segments = self::segments($rawPath);
        if ($segments === null || !self::isRelayed($method, implode('/', $segments))) {
            return null;
        }

        return rtrim($apiRoot, '/') . '/' . implode('/', array_map('rawurlencode', $segments)) . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Serve one /api/manage/* request and end it.
     *
     * @param array<string, string> $incoming the request's headers by name (only FORWARDED_HEADERS are read)
     */
    public static function serve(string $method, string $rawPath, string $query, array $incoming, string $body): never
    {
        $method = strtoupper($method);
        $manage = new ManageClient();
        $url = self::upstreamUrl($method, $rawPath, $query, $manage->apiRoot());
        if ($url === null) {
            self::json(404, 'not_relayed', 'This Manage call is not relayed.');
        }
        if ($manage->base() === '') {
            self::json(503, 'environment_not_configured', Environment::explainUnconfigured());
        }

        $headers = [];
        foreach (self::FORWARDED_HEADERS as $name) {
            $value = trim((string) ($incoming[$name] ?? ''));
            if ($value !== '' && !preg_match('/[\r\n]/', $value)) {
                $headers[] = $name . ': ' . $value;
            }
        }
        $headers[] = 'X-Saas-Origin: ' . Env::get('APP_PRODUCT_KEY', 'messaging');

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT        => $method === 'GET' ? self::READ_TIMEOUT_SECONDS : self::WRITE_TIMEOUT_SECONDS,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        $correlation = '';
        $options[CURLOPT_HEADERFUNCTION] = static function ($handle, string $line) use (&$correlation): int {
            if (stripos($line, 'x-correlation-id:') === 0) {
                $correlation = trim(substr($line, strlen('x-correlation-id:')));
            }

            return strlen($line);
        };

        $raw = false;
        $status = 0;
        $contentType = '';
        if ($ch !== false) {
            curl_setopt_array($ch, $options);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
        }

        if ($raw === false || $status === 0) {
            error_log('[manage-relay] no answer from Manage for ' . $method . ' ' . explode('?', $url)[0]);
            self::json(504, 'manage_unavailable', $method === 'GET'
                ? 'AICOUNTLY Manage did not answer. Try again in a moment.'
                : 'AICOUNTLY Manage did not answer, so it is not known whether the change was made. Reload to check before trying again.');
        }

        http_response_code($status);
        header('Content-Type: ' . ($contentType !== '' ? $contentType : 'application/json'));
        header('Cache-Control: no-store');
        if ($correlation !== '' && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $correlation) === 1) {
            header('X-Correlation-Id: ' . $correlation);
        }
        echo (string) $raw;
        exit;
    }

    private static function json(int $status, string $code, string $message): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['error' => ['code' => $code, 'message' => $message], 'message' => $message], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
