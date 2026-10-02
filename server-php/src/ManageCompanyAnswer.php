<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Reads Manage's `GET /api/companyinfo?comp_id=` answer into allowed / denied /
 * unavailable, and whether the caller OWNS the company. Pure — the HTTP call is
 * Clients\ManageClient::companyInfo, made with the caller's own ses_key.
 *
 * Mirrors contacts-react-app `App\Modules\CompanyContacts\ManageCompanyAnswer`
 * so every product reads Manage's verdict the same way.
 *
 * ## Why ownership comes from here and not from validatesession
 *
 * Messaging used to treat the portal's `acs_type` as "company owner". my.aicountly's
 * validatesession has never returned it — only status, uuid_aictly (an
 * integer) and the session key — so nobody was ever an owner, nobody held
 * messaging.access.manage, and no first permission could ever be granted (I-18).
 * Manage's companyinfo answers for exactly this user and this company and does
 * carry ownership, in one of several spellings across versions:
 * `ownership: "owner"`, `is_creator: true`, `access_type: 1`, or `acs_type: 1`.
 *
 * Manage answers companyinfo only for a company the Bearer's user owns or was
 * shared, so a 2xx carrying that company IS the membership proof:
 *
 *   401 / 403 / 404 / other 4xx → denied      (the caller gets 403)
 *   0 (no answer) / 5xx         → unavailable (the caller gets 503, retryable)
 *   2xx not about this company, or unreadable → unavailable — an answer we
 *                                               cannot read is never a yes.
 *
 * Owner status comes only from this answer — never from anything the client sent.
 */
final class ManageCompanyAnswer
{
    public const ALLOWED     = 'allowed';
    public const DENIED      = 'denied';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @param array<string, mixed>|null $json decoded body, null when absent or not JSON
     *
     * @return array{outcome: string, isOwner: bool, companyName: string, fyList: list<array<string, mixed>>}
     */
    public static function interpret(int $httpStatus, ?array $json, int $cmpId): array
    {
        $no = static fn (string $outcome): array => [
            'outcome' => $outcome, 'isOwner' => false, 'companyName' => '', 'fyList' => [],
        ];

        if ($httpStatus === 0 || $httpStatus >= 500) {
            return $no(self::UNAVAILABLE);
        }
        if ($httpStatus >= 400) {
            return $no(self::DENIED);
        }
        if ($httpStatus < 200 || $httpStatus >= 300 || $json === null) {
            return $no(self::UNAVAILABLE);
        }

        if (array_key_exists('success', $json) && self::isFalsy($json['success'])) {
            return $no(self::DENIED);
        }
        if (array_key_exists('status', $json) && ($json['status'] === 0 || $json['status'] === '0' || $json['status'] === false)) {
            return $no(self::DENIED);
        }

        $data = $json['data'] ?? null;
        if (!is_array($data) || $data === [] || array_is_list($data)) {
            // Some shapes return the company unwrapped at the top level.
            $data = $json;
        }

        $id = null;
        foreach (['comp_id', 'cmp_id', 'company_id', 'compId', 'cmpId'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                $id = trim((string) $data[$key]);
                break;
            }
        }
        if ($id === null || $id !== (string) $cmpId) {
            // Not an answer about this company: misrouted, or a shape we do not know.
            return $no(self::UNAVAILABLE);
        }

        $isOwner = false;
        $ownership = $data['ownership'] ?? null;
        if (is_string($ownership) && strtolower(trim($ownership)) === 'owner') {
            $isOwner = true;
        }
        if (array_key_exists('is_creator', $data) && self::isTruthy($data['is_creator'])) {
            $isOwner = true;
        }
        foreach (['access_type', 'acs_type'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && (string) $data[$key] === '1') {
                $isOwner = true;
            }
        }

        $name = '';
        foreach (['comp_name', 'company_name', 'cmp_name', 'print_name', 'short_name', 'name'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                $name = trim((string) $data[$key]);
                break;
            }
        }

        $fyList = [];
        foreach (is_array($data['fy_list'] ?? null) ? $data['fy_list'] : [] as $fy) {
            if (is_array($fy)) {
                $fyList[] = $fy;
            }
        }

        return ['outcome' => self::ALLOWED, 'isOwner' => $isOwner, 'companyName' => $name, 'fyList' => $fyList];
    }

    private static function isTruthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1'
            || (is_string($v) && in_array(strtolower($v), ['true', 'yes', 'y'], true));
    }

    private static function isFalsy(mixed $v): bool
    {
        return $v === false || $v === 0 || $v === '0'
            || (is_string($v) && in_array(strtolower($v), ['false', 'no', 'n'], true));
    }
}
