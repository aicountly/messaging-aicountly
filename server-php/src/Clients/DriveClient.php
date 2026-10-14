<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Attachments in Aicountly Drive, held by Messaging as Drive's custodian.
 *
 * Messaging does not recreate a document management system: where Drive is
 * connected, an attachment's bytes live in Drive and Messaging keeps a
 * reference. Why the CUSTODIAN API (`/api/custodian/*`, Drive's product-
 * credential contract, the one Auditor's evidence uses) and not the per-user
 * `upload-sessions` one: a message is sent by the dispatch worker, which has
 * no signed-in person, and must still fetch the attachment to hand it to the
 * channel. A custodian document is also read only through the product that
 * holds it — who may open a conversation's attachment is Messaging's decision,
 * made before any Drive call (MessagesController / ConversationService).
 *
 *   POST /api/custodian/upload-sessions?cmp_id=…      (Idempotency-Key)
 *   PUT  {presigned URL}                              the bytes
 *   POST /api/custodian/upload-sessions/{id}/finalize?cmp_id=…
 *   GET  /api/custodian/documents/{id}/download?cmp_id=…   short-lived URL
 *
 * Every call is signed with this product's key (DRIVE_SERVICE_KEY, labelled
 * DRIVE_SERVICE_KEY_ID, default `messaging`) by the AICOUNTLY machine-call
 * signature Drive verifies (drive-react-app ProductSignature):
 *
 *   X-AIC-Signature: t=<unix>,v1=hex(HMAC-SHA256(key, "v1\n" t "\n" METHOD "\n" path "\n"
 *                    canonical_query "\n" sha256(body) "\n" firm "\n" link "\n" actor))
 *
 * Drive must list `messaging` in DOCS_CUSTODIAN_PRODUCTS and hold the same key
 * as `messaging:<key>` in DRIVE_SERVICE_KEYS. Drive releases a custodian
 * download only after a verified (ClamAV) scan; until Drive runs one, a
 * download answers 423 and the message waits rather than going out without
 * its attachment (DispatchService).
 *
 * NEVER A PUBLIC URL. Drive's URL is short-lived, asked for at the moment it
 * is needed and never stored.
 */
final class DriveClient extends ApiClient
{
    private const PUT_TIMEOUT = 60;

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

    /** Drive has no switch: it is configured when Messaging's key is set and Drive's address resolves. */
    public function configured(): bool
    {
        return Features::enabled('DRIVE') && strlen(self::key()) >= 32 && $this->base() !== '';
    }

    public function unavailableMessage(): string
    {
        return 'Attachments are stored in Aicountly Drive, and Drive is not connected on this server (DRIVE_SERVICE_KEY).';
    }

    /**
     * Store one attachment in Drive.
     *
     * @return array{ok:bool, ref:?string, document_id:?int, scan:string, error:?string, retryable:bool}
     */
    public function storeFile(
        int $cmpId,
        int $fyId,
        int $boId,
        string $bytes,
        string $filename,
        string $mimeType,
        string $entityId,
        string $idempotencyKey,
        string $actorUuid = '',
    ): array {
        if (!$this->configured()) {
            return self::failed($this->unavailableMessage(), false);
        }
        $size = strlen($bytes);
        $session = $this->signed('POST', '/upload-sessions', $cmpId, [
            'scope'     => 'product_restricted',
            'custodian' => self::keyId(),
            'context'   => ['cmp_id' => $cmpId, 'fy_id' => $fyId, 'bo_id' => $boId],
            'filename'     => $filename,
            'content_type' => $mimeType,
            'size_bytes'   => $size,
            'entity'       => ['type' => 'messaging_attachment', 'id' => $entityId],
            'acl'          => [],
        ], $actorUuid, ['Idempotency-Key' => $idempotencyKey]);
        $data = $session['data'];
        $sessionId = (int) ($data['session_id'] ?? 0);
        $uploadUrl = (string) ($data['upload_url'] ?? '');
        if (!$session['ok'] || $sessionId <= 0 || $uploadUrl === '') {
            return self::failed($session['error'] ?? 'Drive did not open an upload.', $session['retryable']);
        }
        if (!self::isHttps($uploadUrl)) {
            return self::failed('Drive returned an upload address that is not https.', false);
        }

        $putHeaders = ['Content-Type: ' . $mimeType];
        if (isset($data['headers']) && is_array($data['headers']) && $data['headers'] !== []) {
            $putHeaders = [];
            foreach ($data['headers'] as $name => $value) {
                if (is_string($name) && is_scalar($value) && !preg_match('/[\r\n]/', $name . $value)) {
                    $putHeaders[] = $name . ': ' . $value;
                }
            }
        }
        $put = self::transport('PUT', $uploadUrl, $putHeaders, $bytes, self::PUT_TIMEOUT);
        if ($put['status'] < 200 || $put['status'] >= 300) {
            return self::failed('Drive storage refused the file (HTTP ' . $put['status'] . ').', $put['status'] === 0 || $put['status'] >= 500);
        }

        $finalize = $this->signed('POST', '/upload-sessions/' . $sessionId . '/finalize', $cmpId, [
            'title'      => $filename,
            'size_bytes' => $size,
            'sha256'     => hash('sha256', $bytes),
        ], $actorUuid);
        $document = $finalize['data'];
        $documentId = (int) ($document['document_id'] ?? 0);
        if (!$finalize['ok'] || $documentId <= 0) {
            if (($finalize['code'] ?? '') === 'infected') {
                return ['ok' => false, 'ref' => null, 'document_id' => null, 'scan' => 'infected', 'error' => 'Drive\'s scanner found malware in this file.', 'retryable' => false];
            }

            return self::failed($finalize['error'] ?? 'Drive did not return a document.', $finalize['retryable']);
        }

        return [
            'ok'          => true,
            'ref'         => self::ref($cmpId, $fyId, $boId, $documentId),
            'document_id' => $documentId,
            'scan'        => (string) ($document['scan']['status'] ?? 'pending'),
            'error'       => null,
            'retryable'   => false,
        ];
    }

    /**
     * A short-lived URL for a stored attachment. The caller has already decided
     * the person (or the dispatch of a message) may have it.
     *
     * @return array{ok:bool, url:?string, status:int, code:?string, error:?string, retryable:bool}
     */
    public function downloadUrl(string $ref, string $actorUuid = ''): array
    {
        $parsed = self::parseRef($ref);
        if ($parsed === null) {
            return ['ok' => false, 'url' => null, 'status' => 0, 'code' => 'bad_reference', 'error' => 'Not a Drive attachment reference.', 'retryable' => false];
        }
        if (!$this->configured()) {
            return ['ok' => false, 'url' => null, 'status' => 0, 'code' => 'drive_not_configured', 'error' => $this->unavailableMessage(), 'retryable' => false];
        }
        $result = $this->signed('GET', '/documents/' . $parsed['document_id'] . '/download', $parsed['cmp_id'], null, $actorUuid);
        $url = (string) ($result['data']['url'] ?? '');
        if (!$result['ok'] || !self::isHttps($url)) {
            return ['ok' => false, 'url' => null, 'status' => $result['status'], 'code' => $result['code'], 'error' => $result['error'] ?? 'Drive did not return a link.', 'retryable' => $result['retryable']];
        }

        return ['ok' => true, 'url' => $url, 'status' => $result['status'], 'code' => null, 'error' => null, 'retryable' => false];
    }

    /**
     * Delete a stored attachment for good (company lifecycle purge): Drive's custodian delete needs a
     * passed retention date, so the retention is first set to now, then the document is deleted with
     * the approval reference. Drive keeps a tombstone (hash, size, who, why). A document Drive no
     * longer has (404) counts as deleted.
     *
     * @return array{ok:bool, gone:bool, status:int, error:?string, retryable:bool}
     */
    public function deleteDocument(string $ref, string $approvalRef, string $reason): array
    {
        $parsed = self::parseRef($ref);
        if ($parsed === null) {
            return ['ok' => false, 'gone' => false, 'status' => 0, 'error' => 'Not a Drive attachment reference.', 'retryable' => false];
        }
        if (!$this->configured()) {
            return ['ok' => false, 'gone' => false, 'status' => 0, 'error' => $this->unavailableMessage(), 'retryable' => true];
        }
        $path = '/documents/' . $parsed['document_id'];
        $retention = $this->signed('PUT', $path . '/retention', $parsed['cmp_id'], ['retention_until' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'reason' => $reason], '');
        if (!$retention['ok'] && $retention['status'] !== 404) {
            return ['ok' => false, 'gone' => false, 'status' => $retention['status'], 'error' => (string) $retention['error'], 'retryable' => $retention['retryable']];
        }
        if ($retention['status'] !== 404) {
            $delete = $this->signed('POST', $path . '/custodian-delete', $parsed['cmp_id'], ['approval_ref' => $approvalRef, 'reason' => $reason], '');
            if (!$delete['ok'] && $delete['status'] !== 404) {
                return ['ok' => false, 'gone' => false, 'status' => $delete['status'], 'error' => (string) $delete['error'], 'retryable' => $delete['retryable']];
            }
        }

        return ['ok' => true, 'gone' => $retention['status'] === 404, 'status' => 200, 'error' => null, 'retryable' => false];
    }

    public static function ref(int $cmpId, int $fyId, int $boId, int $documentId): string
    {
        return 'drive:' . $cmpId . ':' . $fyId . ':' . $boId . ':' . $documentId;
    }

    /** @return array{cmp_id:int, fy_id:int, bo_id:int, document_id:int}|null */
    public static function parseRef(string $ref): ?array
    {
        if (preg_match('/^drive:(\d+):(\d+):(\d+):(\d+)$/', trim($ref), $m) !== 1) {
            return null;
        }

        return ['cmp_id' => (int) $m[1], 'fy_id' => (int) $m[2], 'bo_id' => (int) $m[3], 'document_id' => (int) $m[4]];
    }

    /** The machine-call signature headers (byte-for-byte drive-react-app ProductSignature / Auditor RequestSigner). */
    public static function signature(string $keyId, string $key, string $method, string $path, array $query, string $body, string $actor, ?int $now = null): array
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = [(string) $name, (string) $value];
        }
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $canonical = implode('&', array_map(static fn (array $p): string => rawurlencode($p[0]) . '=' . rawurlencode($p[1]), $pairs));
        $t = $now ?? time();
        $toSign = implode("\n", ['v1', (string) $t, strtoupper($method), $path, $canonical, hash('sha256', $body), '', '', $actor]);

        return ['X-AIC-Key-Id' => $keyId, 'X-AIC-Signature' => 't=' . $t . ',v1=' . hash_hmac('sha256', $toSign, $key)];
    }

    /**
     * One signed call to Drive's custodian API.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extra
     * @return array{ok:bool, status:int, data:array<string, mixed>, code:?string, error:?string, retryable:bool}
     */
    private function signed(string $method, string $path, int $cmpId, ?array $body, string $actor, array $extra = []): array
    {
        $actor = preg_match('/[\r\n]/', $actor) === 1 ? '' : $actor;
        $url = $this->apiRoot() . '/custodian' . $path;
        $query = ['cmp_id' => (string) $cmpId];
        $payload = $body !== null ? (string) json_encode($body, JSON_UNESCAPED_SLASHES) : '';
        $headers = self::signature(self::keyId(), self::key(), $method, (string) parse_url($url, PHP_URL_PATH), $query, $payload, $actor)
            + ['Accept' => 'application/json', 'X-AIC-Actor-Uuid' => $actor, 'X-Source-App' => $this->selfName()]
            + ($body !== null ? ['Content-Type' => 'application/json'] : [])
            + $extra;
        $wire = [];
        foreach ($headers as $name => $value) {
            if ($value !== '') {
                $wire[] = $name . ': ' . $value;
            }
        }

        $res = self::transport($method, $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), $wire, $body !== null ? $payload : null, 30);
        $decoded = json_decode($res['body'], true);
        $ok = $res['status'] >= 200 && $res['status'] < 300 && is_array($decoded) && ($decoded['success'] ?? true) !== false;
        if (!$ok) {
            $error = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $decoded['message'] ?? '') : '';
            error_log(sprintf('[cross-service] service=docs outcome=error operation=custodian%s status=%d', explode('?', $path)[0], $res['status']));

            return [
                'ok'        => false,
                'status'    => $res['status'],
                'data'      => [],
                'code'      => is_array($decoded) ? (string) ($decoded['error']['code'] ?? '') : null,
                'error'     => $error !== '' ? $error : ($res['status'] === 0 ? 'Aicountly Drive could not be reached.' : 'HTTP ' . $res['status']),
                'retryable' => $res['status'] === 0 || $res['status'] >= 500 || $res['status'] === 429,
            ];
        }

        return ['ok' => true, 'status' => $res['status'], 'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [], 'code' => null, 'error' => null, 'retryable' => false];
    }

    /**
     * @param list<string> $wire
     * @return array{status:int, body:string}
     */
    private static function transport(string $method, string $url, array $wire, ?string $raw, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => ''];
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $wire,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_REQUIRED,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($raw !== null) {
            $options[CURLOPT_POSTFIELDS] = $raw;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $body === false ? 0 : $status, 'body' => is_string($body) ? $body : ''];
    }

    private static function keyId(): string
    {
        $id = strtolower(trim(Env::get('DRIVE_SERVICE_KEY_ID')));

        return $id !== '' ? $id : 'messaging';
    }

    private static function key(): string
    {
        return trim(Env::get('DRIVE_SERVICE_KEY'));
    }

    private static function isHttps(string $url): bool
    {
        return str_starts_with(strtolower($url), 'https://')
            // The test stub's storage is plain http on loopback, outside production only.
            || (Env::get('APP_ENV') !== 'production' && preg_match('#^http://127\.0\.0\.1:\d+/#', $url) === 1);
    }

    /** @return array{ok:false, ref:null, document_id:null, scan:string, error:string, retryable:bool} */
    private static function failed(string $error, bool $retryable): array
    {
        return ['ok' => false, 'ref' => null, 'document_id' => null, 'scan' => 'none', 'error' => $error, 'retryable' => $retryable];
    }
}
