<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Ai\ConsoleCredentials;
use Aicountly\Api\Ai\DraftAssistant;
use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Channels\ChannelRegistry;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\Settings;
use Aicountly\Api\Features;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class SettingsController extends Controller
{
    /**
     * The session bootstrap: who the caller is, what they may do, what this
     * deployment has.
     *
     * One call, because the shell needs all of it before it can render
     * anything, and three round trips on first paint is three round trips.
     */
    public static function session(): void
    {
        $auth = Auth::require();
        $ctx = Context::fromRequest();
        $ctx->assertAllowed($auth);

        $granted = Permissions::granted($ctx, $auth);
        $settings = Settings::for($ctx);

        $features = [];
        foreach (Features::all() as $flag => $enabled) {
            $features[strtolower($flag)] = [
                'enabled' => $enabled,
                // The reason names environment variables, so it goes only to
                // somebody who could act on it.
                'reason'  => $enabled ? null : (Permissions::allows($ctx, $auth, 'messaging.channels.manage')
                    ? Features::explain($flag)
                    : 'Not connected for this deployment.'),
            ];
        }

        Http::data([
            'user' => [
                'uuid'         => $auth->uuid,
                'name'         => $auth->displayName(),
                'kind'         => $auth->kind,
                'is_owner'     => $auth->accessType() === 1,
            ],
            'context'     => $ctx->asQuery(),
            'permissions' => $granted,
            'catalog'     => Permissions::CATALOG,
            'settings'    => [
                'timezone' => $settings['timezone'],
                'currency' => $settings['currency'],
                'first_response_target_minutes' => $settings['first_response_target_minutes'],
                'resolution_target_minutes'     => $settings['resolution_target_minutes'],
                'quiet_hours_start' => $settings['quiet_hours_start'],
                'quiet_hours_end'   => $settings['quiet_hours_end'],
                'languages' => DraftAssistant::supportedLanguages($ctx),
                'configured' => $settings['configured'],
            ],
            'features'    => $features,
            'ai'          => [
                'available' => AiClient::isAvailable(),
                'permitted' => [
                    'draft'     => (bool) $settings['ai_draft_allowed'],
                    'translate' => (bool) $settings['ai_translate_allowed'],
                    'summarise' => (bool) $settings['ai_summarise_allowed'],
                    'suggest'   => (bool) $settings['ai_suggest_allowed'],
                    'autosend'  => (bool) $settings['ai_autosend_allowed'],
                ],
                'reason'    => ConsoleCredentials::status()['reason'],
            ],
            'channels'    => self::channelSummary($ctx),
        ]);
    }

    public static function show(): void
    {
        [$auth, $ctx] = self::enter('messaging.settings.manage');

        $settings = Settings::for($ctx);
        unset($settings['configured']);

        Http::data([
            'settings' => $settings,
            'notes'    => [
                'first_response_target_minutes' => 'Leave this unset and the Command Centre will not report breaches '
                    . 'of a target nobody agreed to.',
                'quiet_hours' => 'Applied in the company timezone, and only to promotional messages. A customer '
                    . 'waiting on a delivery update at nine in the evening still gets it.',
                'currency'    => 'Spend and outcome figures are reported per currency and never added together '
                    . 'without a stated conversion source.',
                'ai_autosend_allowed' => 'Off by default. Turning it on lets AI-drafted messages reach customers '
                    . 'without anybody reading them.',
            ],
            'available_languages' => [
                'en' => 'English', 'hi' => 'Hindi', 'mr' => 'Marathi', 'gu' => 'Gujarati',
                'ta' => 'Tamil', 'te' => 'Telugu', 'kn' => 'Kannada', 'ml' => 'Malayalam',
                'bn' => 'Bengali', 'pa' => 'Punjabi', 'ur' => 'Urdu',
            ],
        ]);
    }

    public static function update(): void
    {
        [$auth, $ctx] = self::enter('messaging.settings.manage');

        $body = Http::body();
        $before = Settings::for($ctx);

        // Validated rather than trusted. A bad timezone here would make every
        // period on every screen wrong.
        if (isset($body['timezone'])) {
            try {
                new \DateTimeZone((string) $body['timezone']);
            } catch (\Throwable) {
                Http::validationFailed('"' . (string) $body['timezone'] . '" is not a timezone.', ['field' => 'timezone']);
            }
        }
        if (isset($body['currency']) && preg_match('/^[A-Z]{3}$/', (string) $body['currency']) !== 1) {
            Http::validationFailed('Currency must be a three-letter ISO 4217 code.', ['field' => 'currency']);
        }
        foreach (['first_response_target_minutes', 'resolution_target_minutes'] as $field) {
            if (isset($body[$field]) && $body[$field] !== null) {
                $value = (int) $body[$field];
                if ($value < 1 || $value > 10080) {
                    Http::validationFailed('A target must be between 1 minute and one week.', ['field' => $field]);
                }
                $body[$field] = $value;
            }
        }
        foreach (['quiet_hours_start', 'quiet_hours_end'] as $field) {
            if (isset($body[$field]) && $body[$field] !== null && $body[$field] !== ''
                && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $body[$field]) !== 1) {
                Http::validationFailed('Quiet hours must be a time like 21:00.', ['field' => $field]);
            }
        }
        if (isset($body['default_languages'])) {
            if (!is_array($body['default_languages']) || $body['default_languages'] === []) {
                Http::validationFailed('At least one language is required.', ['field' => 'default_languages']);
            }
            $body['default_languages'] = array_values(array_filter(
                array_map(static fn ($l) => is_string($l) ? substr($l, 0, 12) : null, $body['default_languages']),
            ));
        }

        // Enabling autonomous sending is a deliberate act and needs its own
        // permission plus an explicit confirmation flag. It is the one setting
        // on this screen that can put an unreviewed message in front of a
        // customer.
        if (isset($body['ai_autosend_allowed']) && (bool) $body['ai_autosend_allowed'] === true
            && (bool) ($before['ai_autosend_allowed'] ?? false) === false) {
            Permissions::assert($ctx, $auth, 'messaging.ai.manage');

            if (!Http::boolParam('confirm_autonomous_sending')) {
                Http::validationFailed(
                    'Turning on autonomous sending means AI-drafted messages can reach customers without anybody '
                    . 'reading them. Send confirm_autonomous_sending=true to proceed.',
                    ['field' => 'ai_autosend_allowed', 'requires_confirmation' => true],
                );
            }
        }

        $after = Settings::save($ctx, $auth, $body);

        Audit::record($ctx, $auth, 'settings.updated', 'settings', (string) $ctx->cmpId, $before, $after);

        Http::data(['settings' => $after]);
    }

    /** What this caller may do, for the UI to hide what it should. */
    public static function permissions(): void
    {
        $auth = Auth::require();
        $ctx = Context::fromRequest();
        $ctx->assertAllowed($auth);

        Http::data([
            'granted'   => Permissions::granted($ctx, $auth),
            'grantable' => Permissions::grantable($ctx, $auth),
            'catalog'   => Permissions::CATALOG,
            'sensitive' => Permissions::SENSITIVE,
            'note'      => 'Hiding a control in the UI is a courtesy. Every one of these is asserted in the backend '
                . 'before a query runs.',
        ]);
    }

    /** The company switcher. Live from Manage, and NOT company-scoped. */
    public static function companies(): void
    {
        $auth = Auth::require();

        $result = (new ManageClient())->withSession($auth->sesKey())->companies(['limit' => 200]);

        if (!$result['ok']) {
            Http::error(503, 'manage_unavailable',
                'Aicountly Manage owns the list of companies you can open, and it could not be reached. '
                . (string) $result['message'],
                ['retryable' => (bool) $result['retryable']]);
        }

        Http::data([
            'companies'  => $result['body']['data'] ?? $result['body'] ?? [],
            'source'     => 'manage',
            'fetched_at' => $result['fetched_at'],
        ]);
    }

    public static function companyInfo(): void
    {
        $auth = Auth::require();
        $ctx = Context::fromRequest();
        $ctx->assertAllowed($auth);

        $result = (new ManageClient())->withSession($auth->sesKey())->companyInfo($ctx->cmpId);

        if (!$result['ok']) {
            Http::error(503, 'manage_unavailable', (string) $result['message'], ['retryable' => true]);
        }

        Http::data([
            'company'    => $result['body']['data'] ?? $result['body'] ?? [],
            'source'     => 'manage',
            'fetched_at' => $result['fetched_at'],
        ]);
    }

    /** The audit history. */
    public static function audit(): void
    {
        [$auth, $ctx] = self::enter('messaging.audit.view');

        $params = Http::listParams(['created_at'], 'created_at');
        $where = ['cmp_id = :cmp'];
        $bind = ['cmp' => $ctx->cmpId];

        if (($action = Http::param('action', '')) !== '') {
            $where[] = 'action = :action';
            $bind['action'] = $action;
        }
        if (($entityType = Http::param('entity_type', '')) !== '') {
            $where[] = 'entity_type = :entity_type';
            $bind['entity_type'] = $entityType;
        }
        if (($entityId = Http::param('entity_id', '')) !== '') {
            $where[] = 'entity_id = :entity_id';
            $bind['entity_id'] = $entityId;
        }
        if (($actor = Http::param('actor_uuid', '')) !== '') {
            $where[] = 'actor_uuid = :actor';
            $bind['actor'] = $actor;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) (Db::scalar('SELECT COUNT(*) FROM messaging_audit_events WHERE ' . $whereSql, $bind) ?? 0);

        $rows = Db::all(
            'SELECT audit_id, actor_uuid, actor_kind, source_app, action, entity_type, entity_id,
                    before_state, after_state, reason, created_at
             FROM messaging_audit_events WHERE ' . $whereSql . '
             ORDER BY created_at DESC LIMIT :limit OFFSET :offset',
            $bind + ['limit' => $params['limit'], 'offset' => $params['offset']],
        );

        // The two state columns are JSON in the database and are decoded here
        // rather than in the browser: a caller should not have to know which
        // driver build hands back a string and which hands back an array.
        foreach ($rows as $index => $row) {
            $rows[$index]['before_state'] = $row['before_state'] === null ? null : Db::jsonColumn($row['before_state']);
            $rows[$index]['after_state']  = $row['after_state'] === null ? null : Db::jsonColumn($row['after_state']);
        }

        Http::list($rows, $total, $params['limit'], $params['offset'], [
            // The distinct actions actually present for this company, so the
            // filter offers what happened here instead of a hard-coded list
            // that would go stale the moment an action is added.
            'actions' => array_column(Db::all(
                'SELECT DISTINCT action FROM messaging_audit_events WHERE cmp_id = :cmp ORDER BY action',
                ['cmp' => $ctx->cmpId],
            ), 'action'),
            'note' => 'This records Messaging\'s own actions. Where an action turned on another product\'s data, '
                . 'the reference and the decision are recorded — never that product\'s records.',
        ]);
    }

    /** Access control: profiles and who holds them. */
    public static function accessIndex(): void
    {
        [$auth, $ctx] = self::enter('messaging.access.manage');

        $profiles = Db::all(
            'SELECT p.profile_id, p.name, p.description, p.permissions, p.is_active,
                    p.created_at, p.updated_at,
                    (SELECT COUNT(*) FROM messaging_permission_assignments a
                      WHERE a.profile_id = p.profile_id) AS member_count
             FROM messaging_permission_profiles p
             WHERE p.cmp_id = :cmp ORDER BY p.name',
            ['cmp' => $ctx->cmpId],
        );

        foreach ($profiles as $index => $profile) {
            $profiles[$index]['permissions'] = Db::jsonColumn($profile['permissions'] ?? null);
        }

        $assignments = Db::all(
            'SELECT a.assignment_id, a.user_uuid, a.profile_id, p.name AS profile_name, a.created_at
             FROM messaging_permission_assignments a
             JOIN messaging_permission_profiles p ON p.profile_id = a.profile_id
             WHERE a.cmp_id = :cmp ORDER BY a.created_at DESC',
            ['cmp' => $ctx->cmpId],
        );

        Http::data([
            'profiles'    => $profiles,
            'assignments' => $assignments,
            'catalog'     => Permissions::CATALOG,
            'grantable'   => Permissions::grantable($ctx, $auth),
            'defaults'    => Permissions::DEFAULT_MEMBER_GRANTS,
            'sensitive'   => Permissions::SENSITIVE,
            'notes'       => [
                'You can only grant permissions you hold yourself, unless you are the company owner. Otherwise '
                . '"manage access" would be a route to every other permission.',
                'A member with no profile gets a conservative default: they can read the inbox and add notes, but '
                . 'they cannot see financial context, approve a draft or send a message.',
            ],
        ]);
    }

    public static function saveProfile(): void
    {
        [$auth, $ctx] = self::enter('messaging.access.manage');

        $body = Http::body();
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            Http::validationFailed('A profile needs a name.');
        }

        $requested = is_array($body['permissions'] ?? null) ? $body['permissions'] : [];
        $grantable = Permissions::grantable($ctx, $auth);

        $permissions = [];
        $refused = [];
        foreach ($requested as $permission) {
            if (!is_string($permission) || !Permissions::exists($permission)) {
                continue;
            }
            // You cannot grant what you do not hold.
            if (in_array($permission, $grantable, true)) {
                $permissions[] = $permission;
            } else {
                $refused[] = $permission;
            }
        }

        if ($refused !== []) {
            Http::forbidden(
                'You cannot grant permissions you do not hold yourself: ' . implode(', ', $refused) . '. '
                . 'Ask the company owner.',
            );
        }

        $profileId = isset($body['profile_id']) ? (int) $body['profile_id'] : 0;

        if ($profileId > 0) {
            $existing = Db::first(
                'SELECT * FROM messaging_permission_profiles WHERE cmp_id = :cmp AND profile_id = :id',
                ['cmp' => $ctx->cmpId, 'id' => $profileId],
            );
            if ($existing === null) {
                Http::notFound('That profile could not be found.');
            }

            Db::update('messaging_permission_profiles', [
                'name'        => $name,
                'description' => (string) ($body['description'] ?? ''),
                'permissions' => $permissions,
                'is_active'   => (bool) ($body['is_active'] ?? true),
                'updated_at'  => \Aicountly\Api\Support\Clock::nowSql(),
                'updated_by'  => $auth->uuid,
            ], ['cmp_id' => $ctx->cmpId, 'profile_id' => $profileId]);

            Audit::record($ctx, $auth, 'access.profile_updated', 'permission_profile', $profileId, [
                'permissions' => Db::jsonColumn($existing['permissions'] ?? null),
            ], ['permissions' => $permissions]);
        } else {
            $profileId = (int) Db::insert('messaging_permission_profiles', [
                'cmp_id'      => $ctx->cmpId,
                'name'        => $name,
                'description' => (string) ($body['description'] ?? ''),
                'permissions' => $permissions,
                'created_at'  => \Aicountly\Api\Support\Clock::nowSql(),
                'created_by'  => $auth->uuid,
                'updated_at'  => \Aicountly\Api\Support\Clock::nowSql(),
                'updated_by'  => $auth->uuid,
            ], 'profile_id');

            Audit::record($ctx, $auth, 'access.profile_created', 'permission_profile', $profileId, null, [
                'permissions' => $permissions,
            ]);
        }

        // The caller may have just changed their own grants.
        Permissions::forget();

        Http::data(['profile_id' => $profileId, 'permissions' => $permissions], 201);
    }

    public static function assignProfile(): void
    {
        [$auth, $ctx] = self::enter('messaging.access.manage');

        $body = Http::body();
        $userUuid = trim((string) ($body['user_uuid'] ?? ''));
        $profileId = (int) ($body['profile_id'] ?? 0);

        if ($userUuid === '' || $profileId <= 0) {
            Http::validationFailed('user_uuid and profile_id are required.');
        }

        $profile = Db::first(
            'SELECT profile_id, permissions FROM messaging_permission_profiles
             WHERE cmp_id = :cmp AND profile_id = :id',
            ['cmp' => $ctx->cmpId, 'id' => $profileId],
        );
        if ($profile === null) {
            Http::notFound('That profile could not be found.');
        }

        // The profile's permissions must all be ones this caller could grant,
        // or assigning it is a way round the check in saveProfile.
        $grantable = Permissions::grantable($ctx, $auth);
        $escalation = array_values(array_diff(Db::jsonColumn($profile['permissions'] ?? null), $grantable));
        if ($escalation !== []) {
            Http::forbidden(
                'That profile includes permissions you do not hold (' . implode(', ', $escalation) . '), so you '
                . 'cannot assign it.',
            );
        }

        Db::run(
            'INSERT INTO messaging_permission_assignments (cmp_id, user_uuid, profile_id, created_at, created_by)
             VALUES (:cmp, :uuid, :profile, NOW(), :actor)
             ON CONFLICT (cmp_id, user_uuid, profile_id) DO NOTHING',
            ['cmp' => $ctx->cmpId, 'uuid' => $userUuid, 'profile' => $profileId, 'actor' => $auth->uuid],
        );

        Audit::record($ctx, $auth, 'access.profile_assigned', 'permission_assignment', $userUuid, null, [
            'profile_id' => $profileId,
        ]);

        Permissions::forget();

        Http::data(['assigned' => true], 201);
    }

    public static function revokeProfile(): void
    {
        [$auth, $ctx] = self::enter('messaging.access.manage');

        $userUuid = trim((string) (Http::param('user_uuid') ?? ''));
        $profileId = Http::intParam('profile_id', 0) ?? 0;

        if ($userUuid === '' || $profileId <= 0) {
            Http::validationFailed('user_uuid and profile_id are required.');
        }

        Db::run(
            'DELETE FROM messaging_permission_assignments
             WHERE cmp_id = :cmp AND user_uuid = :uuid AND profile_id = :profile',
            ['cmp' => $ctx->cmpId, 'uuid' => $userUuid, 'profile' => $profileId],
        );

        Audit::record($ctx, $auth, 'access.profile_revoked', 'permission_assignment', $userUuid, null, [
            'profile_id' => $profileId,
        ]);

        Permissions::forget();

        Http::data(['revoked' => true]);
    }

    /** @return array<string, mixed> */
    private static function channelSummary(Context $ctx): array
    {
        $rows = Db::all(
            'SELECT channel, status, COUNT(*) AS n FROM messaging_channel_connections
             WHERE cmp_id = :cmp AND is_active = TRUE GROUP BY channel, status',
            ['cmp' => $ctx->cmpId],
        );

        $byChannel = [];
        foreach ($rows as $row) {
            $byChannel[(string) $row['channel']][(string) $row['status']] = (int) $row['n'];
        }

        $connected = [];
        foreach ($byChannel as $channel => $statuses) {
            if (($statuses['connected'] ?? 0) > 0) {
                $connected[] = $channel;
            }
        }

        return [
            'by_channel' => $byChannel,
            'connected'  => $connected,
            'any_connected' => $connected !== [],
            'planned'    => array_keys(ChannelRegistry::PLANNED),
        ];
    }
}
