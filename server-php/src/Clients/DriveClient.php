<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;
use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Documents and attachments — Aicountly Drive / Vault.
 *
 * Messaging does not recreate a document management system. Where Drive is
 * configured, an attachment on a conversation is a Drive document uuid and the
 * file lives there under Drive's own access controls.
 *
 * Where Drive is NOT configured, attachments still have to work — a customer
 * sending a photo of a damaged delivery cannot wait for an integration — so the
 * bytes go to this product's own private storage under a key that is not a
 * guessable path, served only through an authorised expiring URL. That
 * fallback is deliberately the lesser option and is reported as such on the
 * Channels & Trust screen.
 *
 * NEVER A PUBLIC URL, on either path. A message attachment is a customer's
 * document; a public bucket is a disclosure with a CDN in front of it.
 */
final class DriveClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'docs';
    }

    protected function productionBase(): string
    {
        return 'https://drive.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://drive.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'DRIVE_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('DRIVE');
    }

    public function unavailableMessage(): string
    {
        return 'Aicountly Drive is not connected. Attachments use this product\'s own private storage instead.';
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->authorization = 'Bearer ' . trim($sesKey);

        return $clone;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        if ($this->authorization !== '') {
            return ['Authorization' => $this->authorization];
        }

        return ['X-Service-Key' => Env::get('DRIVE_SERVICE_KEY')];
    }

    /**
     * A short-lived, authorised URL for a document.
     *
     * Requested at the moment it is needed and never stored — a stored URL with
     * a token in it is a stored credential, and one that outlives the session
     * that was entitled to it.
     */
    public function downloadUrl(Context $ctx, string $documentUuid): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'v1/documents/' . rawurlencode($documentUuid) . '/download-url' . self::query($ctx->asQuery()),
            null,
            $this->headers(),
        );
    }

    /** @param array<string, mixed> $payload */
    public function store(Context $ctx, array $payload, string $idempotencyKey): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'POST',
            'v1/documents',
            $payload + $ctx->asQuery(),
            $this->headers() + ['Idempotency-Key' => $idempotencyKey],
            true,
        );
    }
}
