<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

/**
 * Company, branch and access context — Aicountly Manage.
 *
 * The ONE integration Messaging treats as non-optional, and it has no Features
 * flag for that reason. Manage decides which companies a session may open, and
 * that decision is the tenant boundary of this entire product (see
 * src/Context.php). A flag that could turn it off would only ever be used to
 * hide a misconfiguration, and what it would hide is the check that stops one
 * business reading another's conversations.
 *
 * Nothing about a company is stored here. The company switcher in the top bar
 * is a live read; the company name on a screen is a live read. A copied company
 * name is a name that stays wrong after somebody corrects it in Manage, and a
 * copied access list is an ex-employee who can still open the inbox.
 */
final class ManageClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'manage';
    }

    protected function productionBase(): string
    {
        return 'https://manage.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://manage.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'MANAGE_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->authorization = 'Bearer ' . trim($sesKey);

        return $clone;
    }

    /** @param array<string, mixed> $filters */
    public function companies(array $filters = []): array
    {
        return $this->request('GET', 'companies' . self::query($filters), null, ['Authorization' => $this->authorization]);
    }

    public function companyInfo(int $cmpId): array
    {
        return $this->request(
            'GET',
            'companyinfo' . self::query(['comp_id' => $cmpId]),
            null,
            ['Authorization' => $this->authorization],
        );
    }

    /**
     * The people who can open this company, for the assignment picker.
     *
     * Read live and never stored. An inbox that offers a departed colleague as
     * an assignee is an inbox with conversations nobody is reading.
     */
    public function members(int $cmpId): array
    {
        return $this->request(
            'GET',
            'companies/' . $cmpId . '/share',
            null,
            ['Authorization' => $this->authorization],
        );
    }
}
