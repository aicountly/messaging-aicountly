<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * This product's own permissions, layered over the portal identity.
 *
 * THE DISTINCTION THIS CATALOGUE EXISTS FOR: a user permitted to answer
 * messages is NOT automatically permitted to view financial balances.
 * `messaging.conversations.reply` and `messaging.context.financial` are two
 * separate grants, and an agent who holds the first and not the second gets an
 * inbox that works and a business-context panel that says the financial part is
 * not theirs to see. That is the common case in a real business — the person
 * answering WhatsApp is not the person who chases money — and a messaging
 * product that leaks every customer's outstanding balance to whoever staffs the
 * inbox is not one anybody should deploy.
 *
 * It is belt and braces, not the only defence. Financial context is fetched
 * from Books UNDER THE USER'S OWN SESSION (see Auth::sesKey), so Books refuses
 * it independently. This catalogue is what lets the UI hide the panel instead of
 * rendering an error, and what stops a Messaging-side aggregate from quietly
 * summing what the user could not read one at a time.
 *
 * ENFORCED IN THE BACKEND. Hiding a panel in React is a courtesy, not a
 * control — the API route is one curl away.
 */
final class Permissions
{
    public const TABLE_PROFILES    = 'messaging_permission_profiles';
    public const TABLE_ASSIGNMENTS = 'messaging_permission_assignments';

    /** @var array<string, array<string, string>> */
    public const CATALOG = [
        'Workspaces' => [
            'messaging.command_centre.view' => 'Open the Messaging Command Centre',
            'messaging.outcomes.view'       => 'View Business Outcomes and attribution evidence',
            'messaging.export'              => 'Export conversations, outcomes and audit data',
        ],
        'Conversations' => [
            'messaging.conversations.view'      => 'View conversations in the Unified Inbox',
            'messaging.conversations.view_all'  => 'View conversations assigned to other agents',
            'messaging.conversations.reply'     => 'Draft and send replies to customers',
            'messaging.conversations.assign'    => 'Assign and reassign conversations',
            'messaging.conversations.resolve'   => 'Resolve and reopen conversations',
            'messaging.conversations.note'      => 'Add internal notes',
        ],
        'Business context' => [
            // Deliberately separate from replying. See the class comment.
            'messaging.context.financial'   => 'View invoice, balance and payment context from Books and Pay',
            'messaging.context.commercial'  => 'View order and appointment context from Sales and Appointments',
        ],
        'Approval and dispatch' => [
            'messaging.drafts.approve'  => 'Approve a draft for sending',
            'messaging.messages.send'   => 'Dispatch an approved message',
            'messaging.dispatch.manage' => 'Investigate and reconcile delivery failures',
        ],
        'Journeys and templates' => [
            'messaging.templates.view'    => 'View message templates',
            'messaging.templates.manage'  => 'Create and edit templates',
            'messaging.templates.submit'  => 'Submit a template to the provider for approval',
            'messaging.journeys.view'     => 'View journeys and their run history',
            'messaging.journeys.manage'   => 'Create and edit journeys',
            'messaging.journeys.publish'  => 'Publish a journey version so it can execute',
            'messaging.journeys.simulate' => 'Run a journey in test mode',
        ],
        'Trust and administration' => [
            'messaging.consent.view'       => 'View consent records and the suppression list',
            'messaging.consent.manage'     => 'Record, withdraw and suppress consent',
            'messaging.consent.override'   => 'Override a suppression where policy permits',
            'messaging.channels.view'      => 'View channel connections and delivery health',
            'messaging.channels.manage'    => 'Connect and configure channels and credentials',
            'messaging.ai.manage'          => 'Change what Messaging AI is allowed to do',
            'messaging.settings.manage'    => 'Change Messaging settings and policies',
            'messaging.access.manage'      => 'Manage Messaging permission profiles',
            'messaging.audit.view'         => 'View the Messaging audit history',
        ],
    ];

    /**
     * What a company gets before anybody has configured anything.
     *
     * Without this, the first person into a brand-new company sees a working
     * sign-in and a wall of refusals, which reads as a broken product rather
     * than as an administrative step nobody has taken yet. The owner (portal
     * acs_type 1) holds everything regardless; this is for everybody else on
     * day one.
     *
     * READ THE OMISSIONS. No `context.financial`, so a new member does not see
     * balances by default. No `messages.send` and no `drafts.approve`, so
     * nothing reaches a customer until somebody is given that grant
     * deliberately. No consent, channel, AI or access administration.
     *
     * @var list<string>
     */
    public const DEFAULT_MEMBER_GRANTS = [
        'messaging.command_centre.view',
        'messaging.conversations.view',
        'messaging.conversations.note',
        'messaging.templates.view',
        'messaging.journeys.view',
    ];

    /**
     * Permissions that are a strict escalation and are never granted by
     * default, even to a profile that was saved with them by an older build.
     *
     * `consent.override` reaches past somebody's opt-out. It stays an explicit,
     * separately-granted, separately-audited act.
     *
     * @var list<string>
     */
    public const SENSITIVE = [
        'messaging.consent.override',
        'messaging.channels.manage',
        'messaging.ai.manage',
        'messaging.access.manage',
    ];

    /** @var array<string, list<string>> */
    private static array $cache = [];

    public static function assert(Context $ctx, Auth $auth, string $permission): void
    {
        if (!self::allows($ctx, $auth, $permission)) {
            Http::forbidden('You do not have permission to ' . self::describe($permission) . '.');
        }
    }

    public static function allows(Context $ctx, Auth $auth, string $permission): bool
    {
        if ($auth->isService()) {
            return true;
        }
        if ($auth->accessType() === 1) {
            return true;
        }

        return in_array($permission, self::granted($ctx, $auth), true);
    }

    /** @return list<string> */
    public static function granted(Context $ctx, Auth $auth): array
    {
        $key = $ctx->cmpId . ':' . $auth->uuid;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if ($auth->isService() || $auth->accessType() === 1) {
            return self::$cache[$key] = self::all();
        }

        try {
            $rows = Db::all(
                'SELECT p.permissions
                 FROM ' . self::TABLE_ASSIGNMENTS . ' a
                 JOIN ' . self::TABLE_PROFILES . ' p ON p.profile_id = a.profile_id
                 WHERE a.cmp_id = :cmp AND a.user_uuid = :uuid AND p.is_active = TRUE',
                ['cmp' => $ctx->cmpId, 'uuid' => $auth->uuid],
            );
        } catch (\Throwable $e) {
            // A permissions lookup that fails must not fail open. An empty
            // grant list is a locked-out user, which is recoverable; the
            // alternative is an unauthorised one, which is not.
            error_log('[permissions] lookup failed: ' . $e->getMessage());

            return self::$cache[$key] = [];
        }

        if ($rows === []) {
            return self::$cache[$key] = self::DEFAULT_MEMBER_GRANTS;
        }

        $granted = [];
        foreach ($rows as $row) {
            foreach (Db::jsonColumn($row['permissions'] ?? null) as $permission) {
                if (is_string($permission) && self::exists($permission)) {
                    $granted[$permission] = true;
                }
            }
        }

        return self::$cache[$key] = array_keys($granted);
    }

    /**
     * Drop the memoised grants for a user.
     *
     * The cache is per-request, which is right for reads: `granted()` is called
     * several times while rendering a screen. But an endpoint that CHANGES
     * somebody's profile and then reports the result would answer from the
     * grants it read before the change — including, when the caller edits their
     * own access, telling them the edit did nothing.
     */
    public static function forget(?Context $ctx = null, ?Auth $auth = null): void
    {
        if ($ctx === null || $auth === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$ctx->cmpId . ':' . $auth->uuid]);
    }

    /**
     * The permissions this caller may hand to somebody else.
     *
     * A company owner may grant anything. Anybody else may grant only what they
     * themselves hold — otherwise `access.manage` is not a permission, it is a
     * route to every other permission.
     *
     * @return list<string>
     */
    public static function grantable(Context $ctx, Auth $auth): array
    {
        if ($auth->isService() || $auth->accessType() === 1) {
            return self::all();
        }

        return self::granted($ctx, $auth);
    }

    /** @return list<string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::CATALOG as $group) {
            foreach (array_keys($group) as $permission) {
                $out[] = $permission;
            }
        }

        return $out;
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }

    private static function describe(string $permission): string
    {
        foreach (self::CATALOG as $group) {
            if (isset($group[$permission])) {
                return strtolower($group[$permission]);
            }
        }

        return 'do that';
    }
}
