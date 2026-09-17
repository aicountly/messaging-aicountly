<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

use Aicountly\Api\Db;
use Aicountly\Api\Env;

/**
 * One configured way to reach customers, loaded from the database.
 *
 * NOTE WHAT IS NOT ON THIS OBJECT: the credential. `credentialRef` names an
 * environment key; `credential()` reads it at the moment of use and the value
 * is never stored on the instance, returned from an endpoint, or logged. An
 * object with an `$accessToken` property is an object that ends up in a
 * `var_dump` in an error handler.
 */
final class ChannelConnection
{
    public function __construct(
        public readonly string $connectionUuid,
        public readonly int $cmpId,
        public readonly int $boId,
        public readonly string $channel,
        public readonly string $provider,
        public readonly string $displayName,
        public readonly string $senderAddress,
        public readonly string $providerAccountRef,
        public readonly string $credentialRef,
        public readonly string $webhookSecretRef,
        public readonly string $status,
        public readonly bool $isActive,
        public readonly int $rowVersion,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['connection_uuid'],
            (int) $row['cmp_id'],
            (int) ($row['bo_id'] ?? 0),
            (string) $row['channel'],
            (string) $row['provider'],
            (string) ($row['display_name'] ?? ''),
            (string) ($row['sender_address'] ?? ''),
            (string) ($row['provider_account_ref'] ?? ''),
            (string) ($row['credential_ref'] ?? ''),
            (string) ($row['webhook_secret_ref'] ?? ''),
            (string) ($row['status'] ?? 'setup_pending'),
            (bool) ($row['is_active'] ?? true),
            (int) ($row['row_version'] ?? 1),
        );
    }

    public static function find(int $cmpId, string $connectionUuid): ?self
    {
        $row = Db::first(
            'SELECT * FROM messaging_channel_connections WHERE cmp_id = :cmp AND connection_uuid = :uuid',
            ['cmp' => $cmpId, 'uuid' => $connectionUuid],
        );

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * The secret, read from the server environment at the moment of use.
     *
     * `credential_ref` is an environment variable NAME. The indirection is what
     * lets a credential be rotated on the host without a database write, keeps
     * it out of backups, and means a compromised database yields the names of
     * secrets rather than the secrets.
     *
     * Where Console manages the credential, the ref is `console:<module>` and
     * resolution goes there instead — same principle, one more hop.
     */
    public function credential(): string
    {
        if ($this->credentialRef === '') {
            return '';
        }
        if (str_starts_with($this->credentialRef, 'console:')) {
            // Console-managed provider credentials resolve through the same path
            // as AI keys. Nothing is written to disk. See Ai/ConsoleCredentials.
            return \Aicountly\Api\Ai\ConsoleCredentials::providerSecret(substr($this->credentialRef, 8));
        }

        return Env::get($this->credentialRef);
    }

    public function webhookSecret(): string
    {
        if ($this->webhookSecretRef === '') {
            return '';
        }
        if (str_starts_with($this->webhookSecretRef, 'console:')) {
            return \Aicountly\Api\Ai\ConsoleCredentials::providerSecret(substr($this->webhookSecretRef, 8));
        }

        return Env::get($this->webhookSecretRef);
    }

    /** True when credentials are present. NOT a claim that the provider is answering. */
    public function hasCredential(): bool
    {
        return $this->credential() !== '';
    }

    /**
     * What may be said about this connection in an API response.
     *
     * The allowlist is the point: a `SELECT *` handed to a browser is how a
     * credential ref, and one day a credential, reaches a bundle. `sender_address`
     * is included because an agent needs to know which number a message will come
     * from; the credential is not, and neither is anything derived from it.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'connection_uuid' => $this->connectionUuid,
            'channel'         => $this->channel,
            'provider'        => $this->provider,
            'display_name'    => $this->displayName,
            'sender_address'  => $this->senderAddress,
            'status'          => $this->status,
            'is_active'       => $this->isActive,
            'row_version'     => $this->rowVersion,
            // A boolean, never the value, and never the variable name to a
            // non-administrator (the controller decides who sees the gap text).
            'credential_present' => $this->hasCredential(),
        ];
    }
}
