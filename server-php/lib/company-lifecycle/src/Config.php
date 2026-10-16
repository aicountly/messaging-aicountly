<?php
declare(strict_types=1);

namespace Aicountly\Lifecycle;

/**
 * The product's lifecycle settings (server-side .env only — never the SPA).
 *
 *   LIFECYCLE_WEBHOOK_SECRET    HMAC secret shared with Manage (≥ 32 characters; Manage holds the same
 *                               value as LIFECYCLE_WEBHOOK_SECRET_<APP>). Unset: events answer 503 and
 *                               nothing is restricted or purged.
 *   MANAGE_API_URL              Manage's base URL (https://manage.aicountly.com, sandbox
 *                               https://manage.gh.aicountly.com). A trailing /api is accepted. Required
 *                               for acks; there is no default, so a sandbox never acks production.
 *   MANAGE_PRODUCT_SERVICE_KEY  optional: bearer for Manage's internal/company-status, asked just
 *                               before a company purge runs (a purge Manage has since reversed is
 *                               refused). Unset: the signed request alone decides.
 *   LIFECYCLE_MANIFEST          optional: path of the reviewed purge manifest (default: the path the
 *                               product passes to Kit::fromEnv()).
 */
final class Config
{
    /** @param list<string> $aliases other app_key spellings Manage may use for this product */
    public function __construct(
        public readonly string $appKey,
        public readonly ?string $secret,
        public readonly ?string $manageUrl,
        public readonly ?string $serviceKey = null,
        public readonly array $aliases = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{1,31}$/', $appKey) !== 1) {
            throw new \InvalidArgumentException('appKey must be the Manage catalog id (books, inventory, sales, …)');
        }
    }

    /**
     * @param (callable(string): ?string)|null $env defaults to Config::env()
     * @param list<string> $aliases
     */
    public static function fromEnv(string $appKey, ?callable $env = null, array $aliases = []): self
    {
        $env ??= [self::class, 'env'];
        $secret = trim((string) ($env('LIFECYCLE_WEBHOOK_SECRET') ?? ''));
        $url = trim((string) ($env('MANAGE_API_URL') ?? ''));
        $key = trim((string) ($env('MANAGE_PRODUCT_SERVICE_KEY') ?? ''));

        return new self(
            strtolower($appKey),
            $secret !== '' ? $secret : null,
            $url !== '' ? self::normaliseManageUrl($url) : null,
            $key !== '' ? $key : null,
            array_values(array_map('strtolower', $aliases)),
        );
    }

    /** getenv, then $_ENV, then $_SERVER (CodeIgniter's DotEnv fills the last two). */
    public static function env(string $name): ?string
    {
        $v = getenv($name);
        if ($v === false || $v === '') {
            $v = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }

        return is_scalar($v) ? (string) $v : null;
    }

    public static function normaliseManageUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        return str_ends_with($url, '/api') ? substr($url, 0, -4) : $url;
    }

    public function secretUsable(): bool
    {
        return Signature::usableSecret($this->secret);
    }

    public function accepts(string $appKey): bool
    {
        $appKey = strtolower(trim($appKey));

        return $appKey === $this->appKey || in_array($appKey, $this->aliases, true);
    }

    public function ackUrl(): ?string
    {
        return $this->manageUrl !== null ? $this->manageUrl . '/api/internal/company-lifecycle/ack' : null;
    }

    public function companyStatusUrl(int $cmpId): ?string
    {
        return $this->companyStatusUrlFor($cmpId, $this->appKey);
    }

    /** company-status for another application (a sender asking about its receiver: DeferredSend). */
    public function companyStatusUrlFor(int $cmpId, string $app): ?string
    {
        return $this->manageUrl !== null
            ? $this->manageUrl . '/api/internal/company-status?ids=' . $cmpId . '&app=' . rawurlencode(strtolower(trim($app)))
            : null;
    }
}
