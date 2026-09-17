<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Clients\DriveClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Support\Clock;
use Aicountly\Api\Support\Uuid;

/**
 * Attachments.
 *
 * ## Inbound media is the most hostile input this product handles
 *
 * A PDF sent by a stranger to a business WhatsApp number. Treated accordingly:
 *
 *  - The declared content type is NEVER believed. It is sniffed, and a mismatch
 *    is rejected — an executable called `invoice.pdf` announcing itself as
 *    `application/pdf` is the oldest trick there is.
 *  - It is scanned before it can be forwarded, and a message with an unscanned
 *    or infected attachment cannot be dispatched (see DispatchGuard gate 7).
 *  - Its text is never treated as an instruction to the AI. See Ai/AiClient.
 *  - It is never served from a public URL and never from a path a caller
 *    composes. Access goes through an authorised endpoint that checks the
 *    tenant and the conversation first.
 *
 * ## Where the bytes live
 *
 * Drive, when Drive is configured — it owns documents and applies its own
 * access controls. Otherwise this product's own private object directory, which
 * is deliberately the lesser option: it is outside the document root, the key
 * is random rather than derived from the filename, and Channels & Trust reports
 * that Drive is not connected rather than implying it is.
 */
final class AttachmentService
{
    /** A conservative allowlist. Anything not named here is refused. */
    private const ALLOWED_TYPES = [
        'application/pdf'  => 'pdf',
        'image/jpeg'       => 'jpg',
        'image/png'        => 'png',
        'image/webp'       => 'webp',
        'image/gif'        => 'gif',
        'text/plain'       => 'txt',
        'text/csv'         => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'audio/mpeg'       => 'mp3',
        'audio/ogg'        => 'ogg',
        'video/mp4'        => 'mp4',
    ];

    private const MAX_BYTES = 16 * 1024 * 1024;

    /**
     * Validate and store an uploaded file.
     *
     * @param array{tmp_name:string, name:string, size:int, type:string, error:int} $upload
     * @return array{ok:bool, code:string, detail:string, attachment_uuid:?string}
     */
    public static function store(Context $ctx, string $messageUuid, array $upload, string $sesKey = ''): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return self::reject('upload_failed', 'The file did not upload completely. Try again.');
        }
        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0) {
            return self::reject('empty_file', 'That file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            return self::reject('too_large', 'Attachments are limited to ' . (int) (self::MAX_BYTES / 1048576) . 'MB.');
        }

        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            return self::reject('upload_failed', 'The uploaded file could not be read.');
        }

        // SNIFFED, not declared. The browser's Content-Type is a hint from the
        // client and this is a decision about what we will store and forward.
        $sniffed = self::sniff($tmp);
        if (!isset(self::ALLOWED_TYPES[$sniffed])) {
            return self::reject(
                'type_not_allowed',
                'Files of type ' . $sniffed . ' cannot be attached to a message.',
            );
        }

        $declared = strtolower(trim((string) ($upload['type'] ?? '')));
        if ($declared !== '' && $declared !== $sniffed) {
            // Not necessarily hostile — browsers get this wrong — but it is
            // logged, and the sniffed type is what is stored.
            error_log('[attachments] declared type ' . $declared . ' did not match sniffed ' . $sniffed);
        }

        $checksum = (string) hash_file('sha256', $tmp);
        $filename = self::safeFilename((string) ($upload['name'] ?? 'attachment'), self::ALLOWED_TYPES[$sniffed]);

        $drive = new DriveClient();
        $storage = 'local';
        $storageRef = '';

        if ($drive->configured()) {
            $result = ($sesKey !== '' ? $drive->withSession($sesKey) : $drive)->store($ctx, [
                'filename'    => $filename,
                'media_type'  => $sniffed,
                'byte_size'   => $size,
                'checksum'    => $checksum,
                'content_b64' => base64_encode((string) file_get_contents($tmp)),
                'purpose'     => 'messaging_attachment',
            ], 'att-' . $checksum);

            if ($result['ok']) {
                $storage = 'drive';
                $storageRef = (string) ($result['body']['data']['document_uuid'] ?? $result['body']['document_uuid'] ?? '');
            }
        }

        if ($storage === 'local') {
            $stored = self::storeLocally($tmp, $sniffed);
            if ($stored === null) {
                return self::reject('storage_unavailable', 'The attachment could not be stored. Check MESSAGING_ATTACHMENT_DIR.');
            }
            $storageRef = $stored;
        }

        $uuid = Uuid::v4();
        // Scanning is a real step, not a rubber stamp: `skipped` is recorded
        // honestly when no scanner is configured, and Channels & Trust says so
        // rather than showing a green tick nobody earned.
        $scan = self::scan($storage === 'local' ? self::localPath($storageRef) : null);

        Db::insert('messaging_message_attachments', [
            'attachment_uuid' => $uuid,
            'cmp_id'          => $ctx->cmpId,
            'message_uuid'    => $messageUuid,
            'filename'        => $filename,
            'media_type'      => $sniffed,
            'byte_size'       => $size,
            'checksum_sha256' => $checksum,
            'storage'         => $storage,
            'storage_ref'     => $storageRef,
            'scan_status'     => $scan['status'],
            'scan_detail'     => $scan['detail'],
            'scanned_at'      => $scan['status'] === 'pending' ? null : Clock::nowSql(),
            'created_at'      => Clock::nowSql(),
        ], 'attachment_uuid');

        if ($scan['status'] === 'infected') {
            return self::reject('infected', 'That file failed a malware scan and was not attached.');
        }

        return ['ok' => true, 'code' => 'ok', 'detail' => 'Attached.', 'attachment_uuid' => $uuid];
    }

    /**
     * An authorised, expiring URL for one attachment.
     *
     * Returns null rather than a guess. A dispatch that needs an attachment URL
     * and gets null refuses to send — which is correct, because a message that
     * says "see attached" with a broken link is the thing gate 7 exists to
     * prevent.
     *
     * @param array<string, mixed> $attachment
     */
    public static function authorisedUrl(Context $ctx, array $attachment): ?string
    {
        $storage = (string) $attachment['storage'];
        $ref = (string) $attachment['storage_ref'];

        if ($storage === 'drive') {
            $result = (new DriveClient())->downloadUrl($ctx, $ref);
            if (!$result['ok']) {
                return null;
            }
            $url = (string) ($result['body']['data']['url'] ?? $result['body']['url'] ?? '');

            return $url !== '' ? $url : null;
        }

        if ($storage === 'provider') {
            // A provider media id. Fetched through the adapter's authenticated
            // path when somebody opens it; not a URL we can hand out.
            return null;
        }

        // Locally stored: a signed, expiring URL served by this API. The
        // signature covers the attachment id and the expiry, so neither can be
        // altered, and the endpoint re-checks the tenant regardless.
        $base = rtrim(Env::get('MESSAGING_PUBLIC_BASE_URL'), '/');
        if ($base === '') {
            return null;
        }

        $expires = Clock::now()->getTimestamp() + 900;
        $attachmentUuid = (string) $attachment['attachment_uuid'];
        $signature = self::signAttachment($attachmentUuid, $expires);

        if ($signature === null) {
            return null;
        }

        return $base . '/api/v1/attachments/' . rawurlencode($attachmentUuid)
            . '?expires=' . $expires . '&signature=' . $signature;
    }

    /**
     * Verify a signed attachment URL.
     *
     * The tenant check happens in the controller as well. This only proves the
     * URL was issued by us and has not expired — it is not, on its own,
     * authorisation.
     */
    public static function verifySignature(string $attachmentUuid, int $expires, string $signature): bool
    {
        if ($expires < Clock::now()->getTimestamp()) {
            return false;
        }
        $expected = self::signAttachment($attachmentUuid, $expires);

        return $expected !== null && hash_equals($expected, $signature);
    }

    private static function signAttachment(string $attachmentUuid, int $expires): ?string
    {
        $secret = Env::get('MESSAGING_ATTACHMENT_SIGNING_KEY');
        if ($secret === '') {
            // No key, no signed URLs. Refusing is right: the alternative is an
            // unsigned URL, which is a public one.
            error_log('[attachments] MESSAGING_ATTACHMENT_SIGNING_KEY is not set; no attachment URLs can be issued.');

            return null;
        }

        return hash_hmac('sha256', $attachmentUuid . '|' . $expires, $secret);
    }

    /** @return array<string, mixed>|null */
    public static function find(Context $ctx, string $attachmentUuid): ?array
    {
        if (!Uuid::isValid($attachmentUuid)) {
            return null;
        }

        return Db::first(
            'SELECT a.*, m.conversation_uuid
             FROM messaging_message_attachments a
             JOIN messaging_messages m ON m.message_uuid = a.message_uuid
             WHERE a.cmp_id = :cmp AND a.attachment_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $attachmentUuid],
        );
    }

    /** Absolute path of a locally stored object. Never composed from a request value. */
    public static function localPath(string $storageRef): ?string
    {
        $dir = rtrim(Env::get('MESSAGING_ATTACHMENT_DIR'), '/');
        if ($dir === '' || preg_match('/^[a-f0-9]{2}\/[a-f0-9]{62}(\.[a-z0-9]+)?$/', $storageRef) !== 1) {
            return null;
        }

        return $dir . '/' . $storageRef;
    }

    /**
     * Record inbound provider media as a reference.
     *
     * Not downloaded on receipt. Fetching every inbound image the moment it
     * arrives fills this product's disk with other people's files for nobody's
     * benefit; it is fetched through the adapter when an agent opens it.
     *
     * @param array<string, string> $media
     */
    public static function recordProviderMedia(Context $ctx, string $messageUuid, array $media): string
    {
        $uuid = Uuid::v4();

        Db::insert('messaging_message_attachments', [
            'attachment_uuid' => $uuid,
            'cmp_id'          => $ctx->cmpId,
            'message_uuid'    => $messageUuid,
            'filename'        => self::safeFilename((string) ($media['filename'] ?? 'attachment'), 'bin'),
            // The provider's claim, and labelled as such until it is fetched
            // and sniffed.
            'media_type'      => (string) ($media['declared_type'] ?? 'application/octet-stream'),
            'byte_size'       => 0,
            'storage'         => 'provider',
            'storage_ref'     => (string) ($media['provider_media_id'] ?? ''),
            // Not scanned, because not fetched. It cannot be forwarded in this
            // state — DispatchGuard refuses a pending scan.
            'scan_status'     => 'pending',
            'created_at'      => Clock::nowSql(),
        ], 'attachment_uuid');

        return $uuid;
    }

    // -----------------------------------------------------------------------

    private static function sniff(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $type = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($type) && $type !== '') {
                    return strtolower($type);
                }
            }
        }

        // Without fileinfo we cannot verify the type, and an unverified type
        // must not be accepted into an allowlist. Returning something that is
        // not in the list is the safe failure.
        return 'application/octet-stream';
    }

    /**
     * A filename safe to store and to show.
     *
     * Path separators and traversal are stripped, the extension is the one the
     * sniffed type implies rather than the one the upload claimed, and the
     * length is bounded.
     */
    private static function safeFilename(string $name, string $extension): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^A-Za-z0-9._ -]/', '_', $base) ?? 'attachment';
        $base = trim(preg_replace('/\.+/', '.', $base) ?? $base, '. ');
        if ($base === '') {
            $base = 'attachment';
        }
        $base = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $base) ?? $base;

        return mb_substr($base, 0, 120) . '.' . $extension;
    }

    /** @return string|null the storage ref */
    private static function storeLocally(string $tmp, string $mediaType): ?string
    {
        $dir = rtrim(Env::get('MESSAGING_ATTACHMENT_DIR'), '/');
        if ($dir === '') {
            return null;
        }

        // A random key, not a filename. A key derived from the customer's
        // filename is a key somebody can guess.
        $key = bin2hex(random_bytes(31));
        $ref = substr($key, 0, 2) . '/' . substr($key, 2) . '.' . self::ALLOWED_TYPES[$mediaType];
        $path = $dir . '/' . $ref;

        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o750, true) && !is_dir(dirname($path))) {
            return null;
        }
        if (!copy($tmp, $path)) {
            return null;
        }
        chmod($path, 0o640);

        return $ref;
    }

    /**
     * Scan an object, using whatever this host has.
     *
     * `skipped` is an honest answer and is recorded as one. A product that
     * reported `clean` with no scanner would be worse than useless — it would
     * be actively misleading on a compliance screen.
     *
     * @return array{status:string, detail:?string}
     */
    private static function scan(?string $path): array
    {
        $command = trim(Env::get('MESSAGING_MALWARE_SCAN_COMMAND'));
        if ($command === '' || $path === null || !is_readable($path)) {
            return [
                'status' => 'skipped',
                'detail' => $command === ''
                    ? 'No scanner is configured (MESSAGING_MALWARE_SCAN_COMMAND is unset).'
                    : 'The stored object could not be read for scanning.',
            ];
        }

        // The command is an operator-configured value and the path is one we
        // generated; both are escaped regardless.
        $output = [];
        $exitCode = 1;
        exec(escapeshellcmd($command) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);

        return match ($exitCode) {
            0  => ['status' => 'clean', 'detail' => null],
            1  => ['status' => 'infected', 'detail' => 'The scanner reported this file as infected.'],
            default => ['status' => 'failed', 'detail' => 'The scanner could not complete (exit ' . $exitCode . ').'],
        };
    }

    /** @return array{ok:bool, code:string, detail:string, attachment_uuid:?string} */
    private static function reject(string $code, string $detail): array
    {
        return ['ok' => false, 'code' => $code, 'detail' => $detail, 'attachment_uuid' => null];
    }
}
