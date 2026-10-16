<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

/**
 * product → Manage.
 *
 *  - send(): POST {MANAGE_API_URL}/api/internal/company-lifecycle/ack with X-Product-App and the same
 *    HMAC as Manage's events. Body (built by body()):
 *      company purge  {event_id, cmp_id, app_key, status, retained, detail}
 *      account purge  {event_id, uuid, app_key, status, retained, detail}
 *    status: purged | nothing_to_purge | failed | held (1.1: legal hold, detail {"reason": …}).
 *    Manage answers 200 (also for the same ack again).
 *  - companyLifecycle(): GET {MANAGE_API_URL}/api/internal/company-status?ids=&app= with
 *    MANAGE_PRODUCT_SERVICE_KEY — the Worker's look at Manage just before a company purge runs.
 *
 * Retry policy (EventStore keeps the attempts): 2xx sent; 400/404/409/410/413/422 are permanent
 * (Manage will never take this ack: logged for ops, not retried); anything else — no answer, 401
 * (clock or secret being fixed), 5xx — is retried after delay(): 1, 2, 4 … minutes, at most 6 hours
 * apart, MAX_ATTEMPTS times.
 */
final class AckClient
{
    public const MAX_ATTEMPTS = 15;

    /** held (1.1): a legal hold stops the purge; detail is {"reason": …}. Manage takes purged after it. */
    public const STATUSES = ['purged', 'nothing_to_purge', 'failed', 'held'];

    private const PERMANENT = [400, 404, 409, 410, 413, 422];

    /** @var callable */
    private $http;

    /** @var callable(): int */
    private $clock;

    /**
     * @param callable|null $http see Http::request()
     * @param (callable(): int)|null $clock
     */
    public function __construct(private Config $config, ?callable $http = null, ?callable $clock = null)
    {
        $this->http = $http ?? [Http::class, 'request'];
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The exact ack JSON. Manage keeps at most 100 retained entries of 200 characters and a detail of
     * 2000; longer is cut here so the signed body is what Manage stores.
     *
     * @param array{event_id:string, kind:string, cmp_id:?int, uuid:?string} $job
     * @param list<string> $retained
     */
    public static function body(array $job, string $appKey, string $status, array $retained, string $detail): string
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('ack status is purged, nothing_to_purge, failed or held');
        }
        $body = ['event_id' => $job['event_id']];
        if ($job['kind'] === 'account') {
            $body['uuid'] = (string) $job['uuid'];
        } else {
            $body['cmp_id'] = (int) $job['cmp_id'];
        }
        $body += [
            'app_key'  => $appKey,
            'status'   => $status,
            'retained' => array_values(array_slice(array_map(static fn ($r): string => mb_substr((string) $r, 0, 200), $retained), 0, 100)),
            'detail'   => $status === 'held' ? ['reason' => mb_substr($detail, 0, 2000)] : mb_substr($detail, 0, 2000),
        ];

        return (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array{ok:bool, http:int, error:?string, permanent:bool} */
    public function send(string $body): array
    {
        if (!$this->config->secretUsable()) {
            return ['ok' => false, 'http' => 0, 'error' => 'LIFECYCLE_WEBHOOK_SECRET is not set; the ack cannot be signed', 'permanent' => false];
        }
        $url = $this->config->ackUrl();
        if ($url === null) {
            return ['ok' => false, 'http' => 0, 'error' => 'MANAGE_API_URL is not set; nowhere to send the ack', 'permanent' => false];
        }
        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            Signature::HEADER_PRODUCT . ': ' . $this->config->appKey,
        ], Signature::headerLines((string) $this->config->secret, $body, ($this->clock)()));
        $res = ($this->http)('POST', $url, $headers, $body, 15);
        $status = (int) ($res['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'http' => $status, 'error' => null, 'permanent' => false];
        }
        $text = (string) ($res['body'] ?? '');
        $decoded = json_decode($text, true);
        $code = is_array($decoded) ? (string) ($decoded['error']['code'] ?? $decoded['code'] ?? '') : '';
        $error = $status === 0
            ? 'no answer: ' . (string) ($res['error'] ?? 'unknown')
            : 'Manage answered ' . $status . ($code !== '' ? ' ' . $code : '') . ($text !== '' && $code === '' ? ': ' . mb_substr($text, 0, 200) : '');

        return ['ok' => false, 'http' => $status, 'error' => $error, 'permanent' => in_array($status, self::PERMANENT, true)];
    }

    /** Seconds before attempt $attempt + 1: 1, 2, 4 … minutes, at most six hours. */
    public static function delay(int $attempt): int
    {
        return (int) min(21600, 60 * (2 ** max(0, min(20, $attempt - 1))));
    }

    /**
     * Manage's lifecycle for the company in this application right now.
     *
     * @return array{known:bool, lifecycle:?string, status:?string, error:?string}
     *   known false: not asked (no service key / URL) or no usable answer.
     */
    public function companyLifecycle(int $cmpId): array
    {
        $url = $this->config->companyStatusUrl($cmpId);
        if ($this->config->serviceKey === null || $url === null) {
            return ['known' => false, 'lifecycle' => null, 'status' => null, 'error' => 'MANAGE_PRODUCT_SERVICE_KEY or MANAGE_API_URL is not set'];
        }
        $res = ($this->http)('GET', $url, ['Accept: application/json', 'Authorization: Bearer ' . $this->config->serviceKey], null, 10);
        $status = (int) ($res['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            return ['known' => false, 'lifecycle' => null, 'status' => null, 'error' => $status === 0 ? 'no answer' : 'Manage answered ' . $status];
        }
        $decoded = json_decode((string) ($res['body'] ?? ''), true);
        $rows = is_array($decoded) ? ($decoded['data']['companies'] ?? null) : null;
        if (!is_array($rows)) {
            return ['known' => false, 'lifecycle' => null, 'status' => null, 'error' => 'answer not understood'];
        }
        $row = $rows[(string) $cmpId] ?? null;
        if (!is_array($row)) {
            return ['known' => true, 'lifecycle' => null, 'status' => 'unknown', 'error' => null];
        }
        $lifecycle = is_string($row['lifecycle'] ?? null) ? strtolower(trim($row['lifecycle'])) : '';

        return [
            'known'     => true,
            'lifecycle' => $lifecycle !== '' ? $lifecycle : null,
            'status'    => is_string($row['status'] ?? null) ? strtolower($row['status']) : null,
            'error'     => null,
        ];
    }
}
