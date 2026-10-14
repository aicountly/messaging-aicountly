<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

/**
 * View/export-only while a company's subscription to this application has lapsed.
 *
 * Call check() wherever the product has already verified the person may open the company (after the
 * Manage companyinfo gate, so a caller who may not open it still gets 403), with the request's method
 * and path. It answers null (go ahead) or [423, payload]:
 *
 *   {"code": "COMPANY_RESTRICTED", "message": "…", "restricted_until": "2026-11-28T00:00:00Z",
 *    "error": {"code": …, "message": …, "restricted_until": …}, "messages": {"error": "…"}}
 *
 *   - GET / HEAD / OPTIONS always pass (viewing is what the window is for);
 *   - a write passes when the company is active (or has no state row) or when it matches the
 *     allow-list — writes that only read, render or export (report queries, PDF/Excel exports,
 *     previews) and the signed-in person's own UI state. Patterns are "METHOD /path" with `*` = one
 *     path segment and `**` = anything, e.g. "POST /api/reports/query", "* /api/exports/**";
 *   - every other write while restricted, purge_pending or purged answers 423.
 *
 * Never turn this into 403/404: the person keeps their seat and must be able to export.
 *
 * Manage's companyinfo is the source of truth: pass its data to syncFromCompanyInfo() wherever the
 * product reads companyinfo (data.lifecycle, data.restricted_until), so a missed event is corrected.
 * If the lifecycle table cannot be read the guard lets the request through and logs it: a database
 * fault must not turn into a false "your subscription ended".
 */
final class ReadOnlyGuard
{
    public const BLOCKING = ['restricted', 'purge_pending', 'purged'];

    public const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var list<array{0:string, 1:string}> [method or *, regex] */
    private array $allow = [];

    /** @var callable(string, string): void */
    private $log;

    /**
     * @param list<string> $allow "METHOD /path/pattern" entries
     * @param (callable(string, string): void)|null $log
     */
    public function __construct(private ?EventStore $store, array $allow = [], ?callable $log = null)
    {
        foreach ($allow as $entry) {
            $this->allow[] = self::compile($entry);
        }
        $this->log = $log ?? [Kit::class, 'defaultLog'];
    }

    /** @return array{0:int, 1:array<string,mixed>}|null */
    public function check(int $cmpId, string $method, string $path): ?array
    {
        $method = strtoupper($method);
        if (in_array($method, self::SAFE_METHODS, true) || $this->allowed($method, $path)) {
            return null;
        }
        if ($this->store === null || $cmpId <= 0) {
            return null;
        }
        try {
            $state = $this->store->state($cmpId);
        } catch (\Throwable $e) {
            ($this->log)('warning', 'lifecycle state unreadable for cmp=' . $cmpId . ', request allowed: ' . substr($e->getMessage(), 0, 200));

            return null;
        }

        return self::decide($state['state'] ?? null, $state['restricted_until'] ?? null, $method, $path, $this->allow);
    }

    /**
     * Pure decision, for products that keep the state elsewhere.
     *
     * @param list<array{0:string,1:string}|string> $allow compiled pairs or raw "METHOD /path" strings
     * @return array{0:int, 1:array<string,mixed>}|null
     */
    public static function decide(?string $state, ?string $restrictedUntil, string $method, string $path, array $allow = []): ?array
    {
        $method = strtoupper($method);
        if ($state === null || !in_array($state, self::BLOCKING, true) || in_array($method, self::SAFE_METHODS, true)) {
            return null;
        }
        foreach ($allow as $rule) {
            $rule = is_string($rule) ? self::compile($rule) : $rule;
            if (($rule[0] === '*' || $rule[0] === $method) && preg_match($rule[1], self::normalisePath($path)) === 1) {
                return null;
            }
        }

        return [423, self::payload($state, $restrictedUntil)];
    }

    /** @return array<string,mixed> */
    public static function payload(string $state, ?string $restrictedUntil): array
    {
        $date = null;
        if ($restrictedUntil !== null && ($ts = strtotime($restrictedUntil)) !== false) {
            $date = gmdate('j M Y', $ts);
        }
        $message = match ($state) {
            'purged'        => 'This company\'s data in this application was deleted after its subscription ended. Renew the subscription to start again.',
            'purge_pending' => 'This company is read-only because its subscription ended, and its data in this application is being deleted. Export what you need now.',
            default         => $date !== null
                ? 'This company is read-only until ' . $date . ' because its subscription ended. Renew to make changes, or export your data.'
                : 'This company is read-only because its subscription ended. Renew to make changes, or export your data.',
        };
        $error = ['code' => 'COMPANY_RESTRICTED', 'message' => $message, 'restricted_until' => $restrictedUntil];

        return $error + ['lifecycle' => $state, 'error' => $error, 'messages' => ['error' => $message]];
    }

    /**
     * Write Manage's companyinfo answer through (it is the source of truth). Pass companyinfo's
     * `data` object; an answer without `lifecycle` (an older Manage) changes nothing.
     *
     * @param array<string,mixed> $companyInfoData
     */
    public function syncFromCompanyInfo(int $cmpId, array $companyInfoData): bool
    {
        $lifecycle = is_string($companyInfoData['lifecycle'] ?? null) ? strtolower(trim($companyInfoData['lifecycle'])) : '';
        if ($this->store === null || $lifecycle === '') {
            return false;
        }
        $until = Receiver::timestamp($companyInfoData['restricted_until'] ?? null);
        try {
            return $this->store->syncFromCompanyInfo($cmpId, $lifecycle, is_string($until) ? $until : null);
        } catch (\Throwable $e) {
            ($this->log)('warning', 'companyinfo lifecycle not recorded for cmp=' . $cmpId . ': ' . substr($e->getMessage(), 0, 200));

            return false;
        }
    }

    private function allowed(string $method, string $path): bool
    {
        $path = self::normalisePath($path);
        foreach ($this->allow as [$m, $re]) {
            if (($m === '*' || $m === $method) && preg_match($re, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function normalisePath(string $path): string
    {
        $path = (string) parse_url($path, PHP_URL_PATH);

        return '/' . trim($path, '/');
    }

    /** @return array{0:string, 1:string} */
    private static function compile(string $entry): array
    {
        $parts = preg_split('/\s+/', trim($entry), 2);
        if (count($parts) !== 2 || preg_match('/^(\*|GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS)$/i', $parts[0]) !== 1) {
            throw new \InvalidArgumentException('Allow-list entries are "METHOD /path/pattern": ' . $entry);
        }
        $glob = '/' . trim($parts[1], '/');
        $re = '';
        foreach (preg_split('/(\*\*|\*)/', $glob, -1, PREG_SPLIT_DELIM_CAPTURE) as $piece) {
            $re .= match ($piece) {
                '**'    => '.*',
                '*'     => '[^/]+',
                default => preg_quote($piece, '#'),
            };
        }

        return [strtoupper($parts[0]), '#^' . $re . '$#'];
    }
}
