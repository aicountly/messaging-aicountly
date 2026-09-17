<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;
use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Orders — Aicountly Sales.
 *
 * "Where is my order?" is the single most common thing a customer sends a
 * business, and answering it is the clearest case for reading live rather than
 * copying. An order's status changes while the conversation is open: it is
 * picked, packed, dispatched. A `last_known_status` column here would have an
 * agent telling a customer their parcel is still in the warehouse an hour after
 * it left.
 *
 * Messaging keeps an order REFERENCE against a conversation so the thread can
 * be found again, and reads the order when it draws it.
 */
final class SalesClient extends ApiClient
{
    private string $authorization = '';
    private string $actorUuid = '';

    public function service(): string
    {
        return 'sales';
    }

    protected function productionBase(): string
    {
        return 'https://sales.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://sales.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'SALES_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('SALES');
    }

    public function unavailableMessage(): string
    {
        return 'Aicountly Sales is not connected, so order context cannot be shown.';
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->authorization = 'Bearer ' . trim($sesKey);
        $clone->actorUuid = '';

        return $clone;
    }

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

        return ['X-Service-Key' => Env::get('SALES_SERVICE_KEY'), 'X-Actor-Uuid' => $this->actorUuid];
    }

    /** The customer's recent orders, newest first, for the business-context panel. */
    public function ordersForContact(Context $ctx, string $contactUuid, int $limit = 5): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'v1/orders' . self::query(
                ['contact_uuid' => $contactUuid, 'limit' => max(1, min(25, $limit)), 'sort' => 'order_date', 'order' => 'desc']
                + $ctx->asQuery(),
            ),
            null,
            $this->authHeaders(),
        );
    }

    public function order(Context $ctx, string $orderRef): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'v1/orders/' . rawurlencode($orderRef) . self::query($ctx->asQuery()),
            null,
            $this->authHeaders(),
        );
    }

    /**
     * Orders in a window, for Business Outcomes attribution.
     *
     * @param array<string, mixed> $filters
     */
    public function ordersInWindow(Context $ctx, string $fromIso, string $toIso, array $filters = []): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'v1/orders' . self::query(['from' => $fromIso, 'to' => $toIso, 'limit' => 200] + $filters + $ctx->asQuery()),
            null,
            $this->authHeaders(),
        );
    }

    /** Fulfilment state for an order-update journey. */
    public function fulfilmentStatus(Context $ctx, string $orderRef): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'v1/orders/' . rawurlencode($orderRef) . '/fulfilment-status' . self::query($ctx->asQuery()),
            null,
            $this->authHeaders(),
        );
    }
}
