<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Support\Clock;

/**
 * Messaging's own settings, with defaults that fail safe.
 *
 * The defaults matter more than the mechanism. A company that has configured
 * nothing gets: AI drafting on, AI SENDING OFF, no response target (so the
 * Command Centre does not invent one and then report breaches of it), and no
 * quiet hours. Every one of those is the conservative choice, and the one that
 * is most important is `ai_autosend_allowed => false` — a product that ships
 * with autonomous sending on by default is a product that sends something
 * nobody read.
 */
final class Settings
{
    /** @var array<int, array<string, mixed>> */
    private static array $memo = [];

    /** @return array<string, mixed> */
    public static function for(Context $ctx): array
    {
        if (isset(self::$memo[$ctx->cmpId])) {
            return self::$memo[$ctx->cmpId];
        }

        try {
            $row = Db::first('SELECT * FROM messaging_settings WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
        } catch (\Throwable $e) {
            error_log('[settings] lookup failed: ' . $e->getMessage());
            $row = null;
        }

        $settings = $row ?? self::defaults();
        $settings['default_languages'] = Db::jsonColumn($settings['default_languages'] ?? null) ?: ['en', 'hi'];
        $settings['configured'] = $row !== null;

        return self::$memo[$ctx->cmpId] = $settings;
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'timezone'                      => 'Asia/Kolkata',
            'currency'                      => 'INR',
            // NULL, not a number. A target nobody agreed to would produce
            // "at risk of missing your response target" warnings against a
            // figure the business never set.
            'first_response_target_minutes' => null,
            'resolution_target_minutes'     => null,
            'quiet_hours_start'             => null,
            'quiet_hours_end'               => null,
            'ai_draft_allowed'              => true,
            'ai_translate_allowed'          => true,
            'ai_summarise_allowed'          => true,
            'ai_suggest_allowed'            => true,
            'ai_autosend_allowed'           => false,
            'default_languages'             => ['en', 'hi'],
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function save(Context $ctx, Auth $auth, array $values): array
    {
        $allowed = [
            'timezone', 'currency', 'first_response_target_minutes', 'resolution_target_minutes',
            'quiet_hours_start', 'quiet_hours_end', 'ai_draft_allowed', 'ai_translate_allowed',
            'ai_summarise_allowed', 'ai_suggest_allowed', 'ai_autosend_allowed', 'default_languages',
        ];

        $update = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $values)) {
                $update[$key] = $values[$key];
            }
        }
        if ($update === []) {
            return self::for($ctx);
        }

        $update['updated_at'] = Clock::nowSql();
        $update['updated_by'] = $auth->uuid;

        $exists = Db::scalar('SELECT 1 FROM messaging_settings WHERE cmp_id = :cmp', ['cmp' => $ctx->cmpId]);
        if ($exists === null) {
            Db::insert('messaging_settings', ['cmp_id' => $ctx->cmpId] + $update, 'cmp_id');
        } else {
            Db::update('messaging_settings', $update, ['cmp_id' => $ctx->cmpId]);
        }

        unset(self::$memo[$ctx->cmpId]);

        return self::for($ctx);
    }

    public static function timezone(Context $ctx): \DateTimeZone
    {
        $name = (string) (self::for($ctx)['timezone'] ?? 'Asia/Kolkata');

        try {
            return new \DateTimeZone($name);
        } catch (\Throwable) {
            return new \DateTimeZone('Asia/Kolkata');
        }
    }

    public static function currency(Context $ctx): string
    {
        return (string) (self::for($ctx)['currency'] ?? 'INR');
    }

    /** CLI only. */
    public static function forget(): void
    {
        if (PHP_SAPI === 'cli') {
            self::$memo = [];
        }
    }
}
