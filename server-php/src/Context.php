<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Clients\ManageClient;

/**
 * The company / branch scope every scoped request carries.
 *
 * Two ids and nothing else. The company's name, its branches and their
 * addresses belong to Manage and are read from Manage at the point of use — a
 * branch whose address was copied once is a branch that keeps the old address
 * on every message footer after somebody corrects it.
 *
 * NO FINANCIAL YEAR. Books, Sales and Purchases scope by `fy_id` because a
 * voucher belongs to an accounting period. A conversation does not: a customer
 * who asked about an invoice in March is still owed an answer in April, and
 * nobody closes a year against a WhatsApp thread. Where Messaging reads a
 * voucher from Books it passes whatever `fy_id` that call needs through to
 * Books rather than scoping its own tables by one.
 *
 * TENANT ISOLATION: `cmp_id` arriving in a query string is a claim, not a fact.
 * assertAllowed() checks it against what Manage says this session may open, and
 * a company the session has no access to is a 403 — never a query that simply
 * returns nothing, which would leak the difference between "no rows" and "not
 * yours" and would break the moment a query forgot its WHERE clause.
 *
 * This is the single most important check in the product. A conversation is
 * somebody's private correspondence with their customer; an attachment is a
 * document they sent. Serving one tenant's inbox to another is not a bug that
 * degrades a screen, it is a disclosure. Nothing about this is per-endpoint:
 * Controllers/Controller::enter() runs it before a controller touches a row.
 */
final class Context
{
    /** @var array<string, bool> */
    private static array $verified = [];

    private function __construct(
        public readonly int $cmpId,
        /** 0 = all locations for this company. */
        public readonly int $boId,
    ) {
    }

    /** Read the scope out of the request, refusing anything incomplete. */
    public static function fromRequest(): self
    {
        $cmpId = Http::intParam('cmp_id', 0) ?? 0;
        $boId  = Http::intParam('bo_id', 0) ?? 0;

        if ($cmpId <= 0) {
            Http::error(400, 'context_required', 'Pick a company first (cmp_id is required).');
        }

        return new self($cmpId, max(0, $boId));
    }

    /** For tests and for the public booking pages, which resolve their company from the page token. */
    public static function forCompany(int $cmpId, int $boId = 0): self
    {
        return new self($cmpId, max(0, $boId));
    }

    /**
     * Confirm this session may open this company, per Manage.
     *
     * Memoised per request because it runs on every scoped endpoint; a failure
     * to reach Manage is a 503 and not an allow, because the alternative is
     * serving one tenant's appointments to another whenever Manage has a bad
     * minute.
     */
    public function assertAllowed(Auth $auth): void
    {
        if ($auth->isService()) {
            // A service key is issued to a product, not to a person, and the
            // owning product has already checked the human behind it.
            return;
        }

        $key = $this->cmpId . ':' . $auth->fingerprint();
        if (isset(self::$verified[$key])) {
            return;
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companyInfo($this->cmpId);

        if (!$result['ok']) {
            // Unreachable is not "allowed". A tenant check that fails open is
            // not a tenant check.
            Http::error(503, 'context_unavailable', 'Cannot confirm company access right now. Please retry.', ['retryable' => true]);
        }

        $body = $result['body'] ?? [];
        $company = $body['data'] ?? $body['company'] ?? $body;
        $resolved = (int) ($company['cmp_id'] ?? $company['comp_id'] ?? $company['id'] ?? 0);

        if ($resolved !== $this->cmpId) {
            Http::forbidden('You do not have access to this company.');
        }

        self::$verified[$key] = true;
    }

    /** CLI only — the test suite stands in for Manage rather than reaching it. */
    public static function trustForTesting(int $cmpId, Auth $auth): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$verified[$cmpId . ':' . $auth->fingerprint()] = true;
    }

    /** @return array{cmp_id:int, bo_id:int} */
    public function asQuery(): array
    {
        return ['cmp_id' => $this->cmpId, 'bo_id' => $this->boId];
    }

    /**
     * The WHERE fragment and bindings every query in this product starts with.
     *
     * `bo_id` 0 means all locations, so it narrows only when it is set — a
     * branch receptionist sees their branch, a company manager sees everything.
     *
     * @return array{0:string, 1:array<string, mixed>}
     */
    public function scopeClause(string $alias = ''): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        $sql = $prefix . 'cmp_id = :ctx_cmp_id';
        $params = ['ctx_cmp_id' => $this->cmpId];

        if ($this->boId > 0) {
            $sql .= ' AND (' . $prefix . 'bo_id = :ctx_bo_id OR ' . $prefix . 'bo_id = 0)';
            $params['ctx_bo_id'] = $this->boId;
        }

        return [$sql, $params];
    }
}
