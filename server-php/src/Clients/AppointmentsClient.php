<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;
use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Appointments — Aicountly Appointments.
 *
 * ## The boundary, which runs the other way from most of these
 *
 * Appointments owns the appointment and the reminder RULES: 24 hours before,
 * the confirmation when a slot is taken, who gets which channel. Messaging owns
 * the DELIVERY. That product's MessagingClient posts to this one's
 * `POST /api/v1/messages`, and this client is how Messaging reads back the
 * appointment behind a reminder it sent.
 *
 * So there are two directions of traffic between these two products, which is
 * exactly the shape that deadlocks a PHP-FPM pool. It does not, because
 * CrossServiceCallContext suppresses a call back to whichever product is
 * currently blocked on us — see src/CrossServiceCallContext.php.
 *
 * ## What Messaging must not do
 *
 * Not invent reminder timing. Not store an appointment time. Not decide that an
 * appointment is confirmed because a reminder was delivered — a delivery receipt
 * says a phone buzzed, not that anybody is coming. Business Outcomes reads
 * confirmations from HERE, which is the only product that knows.
 */
final class AppointmentsClient extends ApiClient
{
    private string $authorization = '';
    private string $actorUuid = '';

    public function service(): string
    {
        return 'appointments';
    }

    protected function productionBase(): string
    {
        return 'https://appointments.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://appointments.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'APPOINTMENTS_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('APPOINTMENTS');
    }

    public function unavailableMessage(): string
    {
        return 'Aicountly Appointments is not connected, so appointment context cannot be shown.';
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

        return ['X-Service-Key' => Env::get('APPOINTMENTS_SERVICE_KEY'), 'X-Actor-Uuid' => $this->actorUuid];
    }

    public function upcomingForContact(Context $ctx, string $contactUuid, int $limit = 3): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'v1/bookings' . self::query(
                ['contact_uuid' => $contactUuid, 'upcoming' => 1, 'limit' => max(1, min(10, $limit))] + $ctx->asQuery(),
            ),
            null,
            $this->authHeaders(),
        );
    }

    public function booking(Context $ctx, string $bookingRef): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'v1/bookings/' . rawurlencode($bookingRef) . self::query($ctx->asQuery()),
            null,
            $this->authHeaders(),
        );
    }

    /**
     * Confirmations in a window, for Business Outcomes.
     *
     * Note what this is NOT: a count of delivered reminders. Appointments knows
     * who confirmed; a delivery receipt does not.
     *
     * @param array<string, mixed> $filters
     */
    public function confirmationsInWindow(Context $ctx, string $fromIso, string $toIso, array $filters = []): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'v1/bookings' . self::query(
                ['from' => $fromIso, 'to' => $toIso, 'status' => 'CONFIRMED', 'limit' => 200] + $filters + $ctx->asQuery(),
            ),
            null,
            $this->authHeaders(),
        );
    }
}
