<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Support\Clock;

/**
 * Replay a write instead of repeating it.
 *
 * ## Why this is not optional on this API
 *
 * `POST /api/v1/messages` is how Appointments asks for a reminder. Appointments
 * retries on a timeout — it should, the alternative is losing reminders. Without
 * this table, that retry is a second message to a customer.
 *
 * The key is scoped by CALLER as well as by company, because two products can
 * legitimately generate the same key. The request hash is stored so that the
 * same key presented with a different body is a 409 rather than a wrong answer:
 * that combination means a caller has a bug, and replaying the first response
 * would hide it.
 */
final class Idempotency
{
    /**
     * Look for a completed response to replay.
     *
     * @return array{status:int, body:array<string, mixed>}|null
     */
    public static function replay(Context $ctx, Auth $auth, string $operation, string $key, array $request): ?array
    {
        if ($key === '') {
            return null;
        }

        $row = Db::first(
            'SELECT request_hash, response_status, response_body, completed_at
             FROM messaging_idempotency_keys
             WHERE cmp_id = :cmp AND caller = :caller AND idempotency_key = :key',
            ['cmp' => $ctx->cmpId, 'caller' => self::caller($auth), 'key' => $key],
        );

        if ($row === null) {
            return null;
        }

        $hash = self::hash($request);
        if ((string) $row['request_hash'] !== $hash) {
            // Same key, different request. The caller has a bug and replaying
            // would mask it.
            Http::conflict(
                'This idempotency key was already used for a different request. Use a new key for a new request.',
                ['reason' => 'idempotency_key_reused'],
            );
        }

        if ($row['completed_at'] === null) {
            // In flight. Telling the caller to retry shortly is better than
            // running the operation a second time alongside the first.
            Http::error(
                409,
                'in_progress',
                'An identical request is still being processed. Retry in a moment.',
                ['retryable' => true],
            );
        }

        return [
            'status' => (int) $row['response_status'],
            'body'   => Db::jsonColumn($row['response_body'] ?? null),
        ];
    }

    /**
     * Claim a key before doing the work.
     *
     * Returns false when another request holds it, which the caller turns into
     * the 409 above. The unique constraint is what makes the claim atomic.
     *
     * @param array<string, mixed> $request
     */
    public static function claim(Context $ctx, Auth $auth, string $operation, string $key, array $request): bool
    {
        if ($key === '') {
            return true;
        }

        try {
            Db::insert('messaging_idempotency_keys', [
                'cmp_id'          => $ctx->cmpId,
                'caller'          => self::caller($auth),
                'idempotency_key' => $key,
                'operation'       => $operation,
                'request_hash'    => self::hash($request),
                'created_at'      => Clock::nowSql(),
            ], 'key_id');

            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @param array<string, mixed> $body */
    public static function complete(Context $ctx, Auth $auth, string $key, int $status, array $body): void
    {
        if ($key === '') {
            return;
        }

        Db::run(
            'UPDATE messaging_idempotency_keys
             SET response_status = :status, response_body = :body, completed_at = :now
             WHERE cmp_id = :cmp AND caller = :caller AND idempotency_key = :key',
            [
                'status' => $status,
                'body'   => json_encode($body, JSON_UNESCAPED_UNICODE),
                'now'    => Clock::nowSql(),
                'cmp'    => $ctx->cmpId,
                'caller' => self::caller($auth),
                'key'    => $key,
            ],
        );
    }

    /**
     * Release a claimed key when the work failed in a way worth retrying.
     *
     * Without this a transient failure would poison the key: the caller retries
     * with the same key, finds an incomplete row, and is told to wait forever.
     */
    public static function release(Context $ctx, Auth $auth, string $key): void
    {
        if ($key === '') {
            return;
        }

        Db::run(
            'DELETE FROM messaging_idempotency_keys
             WHERE cmp_id = :cmp AND caller = :caller AND idempotency_key = :key AND completed_at IS NULL',
            ['cmp' => $ctx->cmpId, 'caller' => self::caller($auth), 'key' => $key],
        );
    }

    /**
     * Proven by the credential, so one caller cannot replay another's key.
     */
    private static function caller(Auth $auth): string
    {
        return $auth->isService() ? 'service:' . $auth->sourceApp : 'user:' . $auth->uuid;
    }

    /** @param array<string, mixed> $request */
    private static function hash(array $request): string
    {
        ksort($request);

        return hash('sha256', json_encode($request, JSON_UNESCAPED_UNICODE) ?: '');
    }
}
