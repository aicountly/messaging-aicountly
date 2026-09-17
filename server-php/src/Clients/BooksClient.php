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
     * Act as this product on behalf of a named human.
     *
     * For a journey step, where there is no browser session to borrow. The
     * actor uuid travels so Books can record who the action was for; it is not
     * a way to read something the actor could not.
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
        if ($this->authorization !== '') {
            return ['Authorization' => $this->authorization];
        }

        return [
            'X-Service-Key' => \Aicountly\Api\Env::get('BOOKS_SERVICE_KEY'),
            'X-Actor-Uuid'  => $this->actorUuid,
        ];
    }

    /**
     * Bill-by-bill outstanding for one party.
     *
     * THIS is the receivables report, and it lives in Books. There is no
     * `messaging_receivables` table and there never will be.
     *
     * @param array<string, mixed> $filters
     */
    public function outstandingForContact(Context $ctx, string $contactUuid, array $filters = []): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'reports/bill-by-bill' . self::query(
                ['contact_uuid' => $contactUuid, 'nature' => 'receivable', 'limit' => 50]
                + $filters + $ctx->asQuery(),
            ),
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

        return $this->request(
            'GET',
            'vouchers/' . rawurlencode($voucherRef) . self::query($ctx->asQuery()),
            null,
            $this->authHeaders(),
        );
    }

    /**
     * Overdue invoices, for the payment-reminder journey's scheduled check.
     *
     * A bounded live query with an explicit limit, processed in memory, and not
     * written down. This is the "configured scheduled operational check" the
     * brief permits: it calls a live API and acts on the result, and it does
     * not copy a single row into this product.
     *
     * @param array<string, mixed> $filters
     */
    public function overdueInvoices(Context $ctx, array $filters = []): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'reports/bill-by-bill' . self::query(
                ['nature' => 'receivable', 'overdue' => 1, 'limit' => 200] + $filters + $ctx->asQuery(),
            ),
            null,
            $this->authHeaders(),
        );
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

        return $this->request(
            'GET',
            'vouchers/' . rawurlencode($voucherRef) . '/document-link' . self::query($ctx->asQuery()),
            null,
            $this->authHeaders(),
        );
    }
}
