<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;
use Aicountly\Api\Features;

/**
 * Invoices, balances and receivables — Aicountly Books.
 *
 * THE MOST IMPORTANT CLIENT IN THIS PRODUCT, because it is the one whose data a
 * messaging product is most tempted to copy and least able to afford copying.
 *
 * A payment reminder is a promise about somebody's money. Sending "your invoice
 * for ₹18,500 is overdue" to a customer who paid it yesterday is not a stale
 * cache, it is a business embarrassing itself in writing on a channel the
 * customer keeps. So:
 *
 *   - No invoice, balance or voucher is stored in this product. Ever.
 *   - The Unified Inbox reads the balance when it draws the panel.
 *   - A payment-reminder journey reads the invoice at the start of the run AND
 *     AGAIN in the moment before dispatch, and cancels if it has been settled.
 *     See Domain/JourneyEngine and Domain/DispatchGuard.
 *   - If Books cannot be reached, a reminder PAUSES. It does not send from the
 *     last thing anybody read.
 *
 * PERMISSIONS ARE BOOKS'. Every call goes out under the signed-in user's own
 * ses_key, so an agent who may answer messages but not see money gets a 403
 * from Books and an honest "you do not have permission to see this" in the
 * panel. Messaging does not hold a key that could read a balance on their
 * behalf, and `messaging.context.financial` is a second lock on the same door
 * rather than the only one.
 */
final class BooksClient extends ApiClient
{
    private string $authorization = '';
    private string $actorUuid = '';

    public function service(): string
    {
        return 'books';
    }

    protected function productionBase(): string
    {
        return 'https://books.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://books.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'BOOKS_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('BOOKS');
    }

    public function unavailableMessage(): string
    {
        return 'Aicountly Books is not connected, so invoice and balance context cannot be shown.';
    }

    /** Act as the signed-in user, so Books applies their permissions and not ours. */
    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->authorization = 'Bearer ' . trim($sesKey);
        $clone->actorUuid = '';

        return $clone;
    }

    /**
     * Nobody signed in (a journey step, the scheduler). Books has no
     * product-key access, so a client built this way reads nothing: every call
     * answers `books_needs_person` instead of being refused over there and
     * read as "nothing owed" (G19#5).
     */
    public function withService(string $actorUuid): self
    {
        $clone = clone $this;
        $clone->authorization = '';
        $clone->actorUuid = trim($actorUuid);

        return $clone;
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        // Only ever the person's own session: every call above refuses first
        // when there is none (needsPerson), because Books accepts no key.
        return ['Authorization' => $this->authorization];
    }

    /**
     * Bill-by-bill outstanding for one Books LEDGER ACCOUNT (G19#5).
     *
     * THIS is the receivables report, and it lives in Books. Books answers it
     * for an `acc_id` in a financial year (`fy_id`) — it has no idea what a
     * Contacts id is, and the old call that sent `contact_uuid` without either
     * was a 400 every time. The acc_id comes from an EXPLICIT reference in
     * Contacts (ContactsClient::ledgerAccount), never from a name match.
     */
    public function outstandingForLedger(Context $ctx, string $accId, int $fyId): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }
        if ($this->authorization === '') {
            return $this->needsPerson();
        }

        return $this->request(
            'GET',
            'reports/bill-by-bill' . self::query(['acc_id' => $accId, 'fy_id' => $fyId] + $ctx->asQuery()),
            null,
            $this->authHeaders(),
        );
    }

    /** One invoice, as it stands right now. */
    public function invoice(Context $ctx, string $voucherRef): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }
        if ($this->authorization === '') {
            return $this->needsPerson();
        }

        return $this->request(
            'GET',
            'vouchers/' . rawurlencode($voucherRef) . self::query($ctx->asQuery()),
            null,
            $this->authHeaders(),
        );
    }

    /**
     * Overdue receivables across the company (Books `reports/dues`, debtors,
     * overdue on `as_on`), for a journey simulation run by a person.
     *
     * A bounded live query with an explicit limit, processed in memory, and not
     * written down. Each row names a Books ledger (acc_id); who that is in
     * Contacts is only what an explicit reference says (G19#5).
     *
     * @param array<string, mixed> $filters
     */
    public function overdueInvoices(Context $ctx, ?int $fyId, string $asOn, array $filters = []): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }
        if ($this->authorization === '') {
            return $this->needsPerson();
        }
        if ($fyId === null) {
            return $this->envelope(false, 0, null, 'books_needs_financial_year', 'unsupported', false,
                'Manage lists no financial year covering ' . $asOn . ' for this company, so Books cannot be asked.');
        }

        return $this->request(
            'GET',
            'reports/dues' . self::query(
                ['party_type' => 'debtor', 'as_on' => $asOn, 'status' => 'overdue', 'limit' => 200, 'fy_id' => $fyId]
                + $filters + $ctx->asQuery(),
            ),
            null,
            $this->authHeaders(),
        );
    }

    /**
     * Books is read as a signed-in person. It has no product-key access, so a
     * call with nobody signed in would only ever be refused there — and a
     * refusal must not read as "nothing is owed".
     */
    private function needsPerson(): array
    {
        return $this->envelope(false, 0, null, 'books_needs_person', 'unsupported', false,
            'Aicountly Books is read as the signed-in person; with nobody signed in it is not read.');
    }

    /**
     * Payments received in a window, for Business Outcomes attribution.
     *
     * Read live at render time. The amounts are Books' and stay Books': what
     * Messaging keeps is the DECISION that a conversation preceded one of them
     * (messaging_outcome_links), never the payment.
     *
     * @param array<string, mixed> $filters
     */
    public function receiptsInWindow(Context $ctx, string $fromIso, string $toIso, array $filters = []): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }
        if ($this->authorization === '') {
            return $this->needsPerson();
        }

        return $this->request(
            'GET',
            'registers' . self::query(
                ['register' => 'receipt', 'from' => $fromIso, 'to' => $toIso, 'limit' => 200]
                + $filters + $ctx->asQuery(),
            ),
            null,
            $this->authHeaders(),
        );
    }

    /**
     * Ask Books for an authorised link to an invoice document.
     *
     * Messaging never renders or stores the PDF. It asks for a link the
     * recipient is entitled to follow, and if Books will not issue one then the
     * draft says the invoice could not be attached — it does not attach nothing
     * and claim otherwise.
     */
    public function invoiceDocumentLink(Context $ctx, string $voucherRef): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }
        if ($this->authorization === '') {
            return $this->needsPerson();
        }

        return $this->request(
            'GET',
            'vouchers/' . rawurlencode($voucherRef) . '/document-link' . self::query($ctx->asQuery()),
            null,
            $this->authHeaders(),
        );
    }
}
