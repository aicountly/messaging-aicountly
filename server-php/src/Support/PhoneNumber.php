<?php

declare(strict_types=1);

namespace Aicountly\Api\Support;

/**
 * Turn what somebody typed — or what Contacts holds — into E.164, using a
 * REGION for national numbers.
 *
 * ## The bug this replaces
 *
 * The old rule stripped everything but digits and put a '+' in front. A
 * ten-digit Indian mobile, 9876543210, became +9876543210 — a valid-looking
 * Iranian number. It was dialled, billed, and checked against the suppression
 * list as that foreign number, so a DND entry typed nationally protected nobody.
 *
 * ## The rule now
 *
 *   +CC…   international; the country's national number length is checked
 *          when the country is one this table knows.
 *   00CC…  the same, with the international prefix.
 *   CC…    digits that are exactly a known country code plus a valid national
 *          number for THAT region ("91 98765 43210" typed in India).
 *   other  a national number in `$region`: the trunk prefix (0 in India) is
 *          dropped and the region's calling code put in front, if the result
 *          has a valid national length.
 *
 * Anything else is null — refused, never guessed. A national number with no
 * region we know is refused too, because a '+' guess is exactly the bug above.
 *
 * Deliberately a small, explicit table rather than a full numbering-plan
 * library (the same table as Voice's): a wrong length here costs a refused
 * number (which says so), not a message to a stranger. Contacts stores phones in
 * canonical E.164 by the same region rule (request region, company setting,
 * CONTACTS_DEFAULT_PHONE_REGION, then IN), so both ends agree on the same digits.
 */
final class PhoneNumber
{
    /**
     * region => [calling code, allowed national-number lengths, trunk prefix]
     *
     * @var array<string, array{0:string, 1:list<int>, 2:string}>
     */
    private const REGIONS = [
        'IN' => ['91', [10], '0'],
        'US' => ['1', [10], '1'],
        'CA' => ['1', [10], '1'],
        'GB' => ['44', [9, 10], '0'],
        'AE' => ['971', [8, 9], '0'],
        'SA' => ['966', [8, 9], '0'],
        'QA' => ['974', [8], ''],
        'OM' => ['968', [8], ''],
        'KW' => ['965', [8], ''],
        'BH' => ['973', [8], ''],
        'SG' => ['65', [8], ''],
        'MY' => ['60', [8, 9, 10], '0'],
        'HK' => ['852', [8], ''],
        'AU' => ['61', [9], '0'],
        'NZ' => ['64', [8, 9, 10], '0'],
        'NP' => ['977', [8, 9, 10], '0'],
        'BD' => ['880', [8, 9, 10], '0'],
        'LK' => ['94', [9], '0'],
        'PK' => ['92', [9, 10], '0'],
        'ZA' => ['27', [9], '0'],
        'KE' => ['254', [9], '0'],
        'FR' => ['33', [9], '0'],
        'JP' => ['81', [9, 10], '0'],
    ];

    /** E.164's own bounds, for a country this table does not know. */
    private const MIN_DIGITS = 8;
    private const MAX_DIGITS = 15;

    /** A region code this class can apply to national numbers, upper-cased, or null. */
    public static function region(?string $region): ?string
    {
        $region = strtoupper(trim((string) $region));

        return isset(self::REGIONS[$region]) ? $region : null;
    }

    /** @return list<string> */
    public static function supportedRegions(): array
    {
        return array_keys(self::REGIONS);
    }

    /**
     * Canonical E.164, or null when the input is not a number we can be sure of.
     *
     * @param string|null $region ISO 3166 alpha-2 for national numbers (e.g. 'IN'); null accepts
     *                            only international forms
     */
    public static function toE164(string $raw, ?string $region = null): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Extensions and letters are not dialable digits; refuse rather than
        // silently drop part of what was typed.
        if (preg_match('/^[+0-9 ().\-\/]+$/', $raw) !== 1) {
            return null;
        }

        $international = str_starts_with($raw, '+');
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        // A second '+' anywhere is not a phone number.
        if (substr_count($raw, '+') > ($international ? 1 : 0)) {
            return null;
        }

        if ($international) {
            return self::validInternational($digits);
        }

        if (str_starts_with($digits, '00')) {
            return self::validInternational(substr($digits, 2));
        }

        $region = self::region($region);
        if ($region === null) {
            return null;
        }
        [$code, $lengths, $trunk] = self::REGIONS[$region];

        // "91 98765 43210": the country code typed without the '+'.
        if (str_starts_with($digits, $code) && in_array(strlen($digits) - strlen($code), $lengths, true)) {
            $candidate = self::validInternational($digits);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        // National: drop the trunk prefix, then the national number must fit.
        $national = $digits;
        if ($trunk !== '' && str_starts_with($national, $trunk) && !in_array(strlen($national), $lengths, true)) {
            $national = substr($national, strlen($trunk));
        }
        if (!in_array(strlen($national), $lengths, true) || $national[0] === '0') {
            return null;
        }

        return self::validInternational($code . $national);
    }

    /**
     * The region an E.164 number belongs to, when this table knows its calling
     * code (the longest code wins; +1 is read as US, which normalises the same
     * national numbers as CA). Used to read a contact's national-format phones
     * in the same country as the number a message came from.
     */
    public static function regionOf(string $e164): ?string
    {
        if (preg_match('/^\+([1-9][0-9]{6,14})$/', trim($e164), $m) !== 1) {
            return null;
        }
        $best = null;
        $bestLength = 0;
        foreach (self::REGIONS as $region => [$code]) {
            if (str_starts_with($m[1], $code) && strlen($code) > $bestLength) {
                $best = $region;
                $bestLength = strlen($code);
            }
        }

        return $best;
    }

    /** Same digits, same answer: are two typed forms one number in this region? */
    public static function same(string $a, string $b, ?string $region = null): bool
    {
        $x = self::toE164($a, $region);

        return $x !== null && $x === self::toE164($b, $region);
    }

    /** `+` and digits after the international prefix, validated, or null. */
    private static function validInternational(string $digits): ?string
    {
        $digits = ltrim($digits, '+');
        if (preg_match('/^[1-9][0-9]+$/', $digits) !== 1) {
            return null;
        }
        $length = strlen($digits);
        if ($length < self::MIN_DIGITS || $length > self::MAX_DIGITS) {
            return null;
        }

        // A country we know must have a national number of a length it uses.
        $known = self::knownCodeOf($digits);
        if ($known !== null) {
            $national = substr($digits, strlen($known));
            $lengths = [];
            foreach (self::REGIONS as [$code, $allowed]) {
                if ($code === $known) {
                    $lengths = array_merge($lengths, $allowed);
                }
            }
            if (!in_array(strlen($national), $lengths, true) || ($national !== '' && $national[0] === '0')) {
                return null;
            }
        }

        return '+' . $digits;
    }

    /** The longest known calling code the digits start with. */
    private static function knownCodeOf(string $digits): ?string
    {
        $best = null;
        foreach (self::REGIONS as [$code]) {
            if (str_starts_with($digits, $code) && ($best === null || strlen($code) > strlen($best))) {
                $best = $code;
            }
        }

        return $best;
    }
}
