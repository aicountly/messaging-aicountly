<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;
use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Payment links and payment status — Aicountly Pay.
 *
 * ## Messaging has no payment gateway and no payment ledger
 *
 * It does not call Razorpay. It does not store a transaction. It does not know
 * what has been collected. It asks Pay for a link, puts that link in a message,
 * and reads the status back when somebody asks. Anything more would mean two
 * products believing they know what a customer has paid.
 *
 * ## The case this class exists to get right
 *
 * A customer asks for their invoice and a payment link. The assistant drafts a
 * reply. Pay is not connected. The honest outcome is a draft that offers the
 * invoice and says plainly that the payment link is not available — and a SEND
 * BUTTON THAT REFUSES the draft if somebody edits it to claim otherwise.
 *
 * The dishonest outcome, and the one this product must never produce, is a
 * message telling a customer a link is attached when nothing is. Approval
 * cannot override it: an approval is permission to send content, not permission
 * to conjure a resource that does not exist. See
 * Domain/DispatchGuard::assertPromisedResourcesExist().
 */
final class PayClient extends ApiClient
{
    public function service(): string
    {
        return 'pay';
    }

    protected function productionBase(): string
    {
        return 'https://pay.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://pay.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'PAY_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('PAY');
    }

    public function unavailableMessage(): string
    {
        return 'Aicountly Pay is not connected, so a payment link cannot be created.';
    }

    /** @return array<string, string> */
    private function headers(string $actorUuid = ''): array
    {
        return [
            'X-Service-Key' => Env::get('PAY_SERVICE_KEY'),
            'X-Actor-Uuid'  => $actorUuid,
        ];
    }

    /**
     * Ask Pay for a payment link against an invoice.
     *
     * @param array{amount_minor:int, currency:string, purpose:string, reference:string, contact_uuid?:string, description?:string} $payload
     */
    public function createPaymentLink(Context $ctx, array $payload, string $actorUuid, string $idempotencyKey): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'POST',
            'v1/payment-links',
            $payload + $ctx->asQuery(),
            $this->headers($actorUuid) + ['Idempotency-Key' => $idempotencyKey],
            true,
        );
    }

    /**
     * Whether a link has been paid. Read live, always.
     *
     * Business Outcomes uses this rather than counting link clicks. A click is
     * not a payment, and a collections figure derived from clicks is a
     * collections figure that is wrong in the optimistic direction.
     */
    public function paymentLink(Context $ctx, string $linkRef): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'v1/payment-links/' . rawurlencode($linkRef) . self::query($ctx->asQuery()),
            null,
            $this->headers(),
        );
    }

    /** @param array<string, mixed> $filters */
    public function paymentsInWindow(Context $ctx, string $fromIso, string $toIso, array $filters = []): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'v1/payments' . self::query(['from' => $fromIso, 'to' => $toIso, 'limit' => 200] + $filters + $ctx->asQuery()),
            null,
            $this->headers(),
        );
    }
}
