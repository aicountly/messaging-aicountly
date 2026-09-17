<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Controllers\AiController;
use Aicountly\Api\Controllers\ChannelsController;
use Aicountly\Api\Controllers\ConsentController;
use Aicountly\Api\Controllers\ContactsController;
use Aicountly\Api\Controllers\ConversationsController;
use Aicountly\Api\Controllers\JourneysController;
use Aicountly\Api\Controllers\MessagesController;
use Aicountly\Api\Controllers\OutcomesController;
use Aicountly\Api\Controllers\OverviewController;
use Aicountly\Api\Controllers\ServiceController;
use Aicountly\Api\Controllers\SettingsController;
use Aicountly\Api\Controllers\TemplatesController;
use Aicountly\Api\Controllers\WebhookController;

/**
 * Every route this API serves.
 *
 * The shape follows the rest of the fleet: `/api/v1/<resource>`, company
 * context on the query string or in the body, `{data}` / `{data, meta}`
 * envelopes.
 *
 * THREE GROUPS, deliberately separated so somebody reading this file can tell
 * at a glance who can reach what:
 *
 *  1. `/api/v1/...`        a signed-in human, or a product with a service key.
 *                          Company-scoped, permission-checked, tenant-checked.
 *  2. `/api/v1/messages`   the CROSS-PRODUCT contract. Service keys only. This
 *     and stats            shape is already called by appointments-aicountly's
 *                          MessagingClient and must not change casually.
 *  3. `/api/webhooks/...`  provider callbacks. NO session and no Auth: a
 *                          delivery receipt from Meta is not a user. Signature
 *                          verified, tenant resolved server-side from the
 *                          connection in the path — never from the payload.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        // ------------------------------------------------------------------
        // 3. Provider webhooks. No session. Grouped FIRST so their absence of
        //    authentication is the first thing a reader sees, not a surprise.
        // ------------------------------------------------------------------
        $router->get('webhooks/{provider}/{connection}', [WebhookController::class, 'verify']);
        $router->post('webhooks/{provider}/{connection}', [WebhookController::class, 'receive']);

        // ------------------------------------------------------------------
        // 2. The cross-product service API.
        //
        //    Declared BEFORE v1/messages/{uuid} would shadow it, and kept
        //    together because they are one published contract.
        // ------------------------------------------------------------------
        $router->post('v1/messages', [ServiceController::class, 'send']);
        $router->get('v1/messages/stats', [ServiceController::class, 'stats']);
        $router->get('v1/messages/{message}', [ServiceController::class, 'show']);

        // ------------------------------------------------------------------
        // Session, company switcher, settings, access, audit.
        // ------------------------------------------------------------------
        $router->get('v1/session', [SettingsController::class, 'session']);
        $router->get('v1/permissions', [SettingsController::class, 'permissions']);
        $router->get('v1/manage/companies', [SettingsController::class, 'companies']);
        $router->get('v1/manage/companyinfo', [SettingsController::class, 'companyInfo']);

        $router->get('v1/settings', [SettingsController::class, 'show']);
        $router->put('v1/settings', [SettingsController::class, 'update']);

        $router->get('v1/access', [SettingsController::class, 'accessIndex']);
        $router->post('v1/access/profiles', [SettingsController::class, 'saveProfile']);
        $router->post('v1/access/assignments', [SettingsController::class, 'assignProfile']);
        $router->delete('v1/access/assignments', [SettingsController::class, 'revokeProfile']);

        $router->get('v1/audit', [SettingsController::class, 'audit']);

        // ------------------------------------------------------------------
        // 1. Command Centre.
        // ------------------------------------------------------------------
        $router->get('v1/overview', [OverviewController::class, 'show']);
        $router->delete('v1/suggestions/{key}', [OverviewController::class, 'dismissSuggestion']);

        // ------------------------------------------------------------------
        // Unified Inbox.
        //
        // The fixed sub-paths are declared BEFORE the {conversation} routes
        // they would otherwise be swallowed by.
        // ------------------------------------------------------------------
        $router->get('v1/conversations', [ConversationsController::class, 'index']);
        $router->get('v1/conversations/assignees', [ConversationsController::class, 'assignees']);
        $router->get('v1/conversations/{conversation}', [ConversationsController::class, 'show']);
        $router->patch('v1/conversations/{conversation}', [ConversationsController::class, 'update']);
        $router->get('v1/conversations/{conversation}/context', [ConversationsController::class, 'context']);
        $router->post('v1/conversations/{conversation}/assign', [ConversationsController::class, 'assign']);
        $router->post('v1/conversations/{conversation}/notes', [ConversationsController::class, 'addNote']);
        $router->post('v1/conversations/{conversation}/match-contact', [ConversationsController::class, 'matchContact']);

        $router->get('v1/conversations/{conversation}/messages', [MessagesController::class, 'index']);
        $router->post('v1/conversations/{conversation}/drafts', [MessagesController::class, 'saveDraft']);
        $router->post('v1/conversations/{conversation}/send', [MessagesController::class, 'send']);

        // ------------------------------------------------------------------
        // Messages: approval, dispatch and delivery investigation.
        // ------------------------------------------------------------------
        $router->get('v1/delivery/failures', [MessagesController::class, 'failures']);
        $router->post('v1/drafts/{message}/approve', [MessagesController::class, 'approve']);
        $router->post('v1/drafts/{message}/attachments', [MessagesController::class, 'attach']);
        $router->post('v1/drafts/{message}/cancel', [MessagesController::class, 'cancel']);
        $router->get('v1/delivery/messages/{message}', [MessagesController::class, 'deliveryEvents']);
        $router->post('v1/delivery/messages/{message}/reconcile', [MessagesController::class, 'reconcile']);
        $router->get('v1/attachments/{attachment}', [MessagesController::class, 'downloadAttachment']);

        // ------------------------------------------------------------------
        // The assistant. Every one of these returns a suggestion and sends
        // nothing.
        // ------------------------------------------------------------------
        $router->get('v1/ai/status', [AiController::class, 'status']);
        $router->post('v1/ai/draft', [AiController::class, 'draft']);
        $router->post('v1/ai/summarise', [AiController::class, 'summarise']);
        $router->post('v1/ai/translate', [AiController::class, 'translate']);
        $router->post('v1/ai/rewrite', [AiController::class, 'rewrite']);
        $router->post('v1/ai/analyse', [AiController::class, 'analyse']);
        $router->post('v1/ai/feedback', [AiController::class, 'feedback']);

        // ------------------------------------------------------------------
        // Templates.
        // ------------------------------------------------------------------
        $router->get('v1/templates', [TemplatesController::class, 'index']);
        $router->post('v1/templates', [TemplatesController::class, 'create']);
        $router->get('v1/templates/preview', [MessagesController::class, 'previewTemplate']);
        $router->get('v1/templates/{template}', [TemplatesController::class, 'show']);
        $router->put('v1/templates/{template}', [TemplatesController::class, 'update']);
        $router->post('v1/templates/{template}/submit', [TemplatesController::class, 'submit']);
        $router->post('v1/templates/{template}/provider-status', [TemplatesController::class, 'recordProviderStatus']);

        // ------------------------------------------------------------------
        // Journeys.
        // ------------------------------------------------------------------
        $router->get('v1/journeys', [JourneysController::class, 'index']);
        $router->post('v1/journeys', [JourneysController::class, 'create']);
        $router->post('v1/journeys/validate', [JourneysController::class, 'validate']);
        $router->post('v1/journeys/propose', [JourneysController::class, 'propose']);
        $router->get('v1/journeys/{journey}', [JourneysController::class, 'show']);
        $router->put('v1/journeys/{journey}', [JourneysController::class, 'update']);
        $router->post('v1/journeys/{journey}/publish', [JourneysController::class, 'publish']);
        $router->post('v1/journeys/{journey}/status', [JourneysController::class, 'setStatus']);
        $router->post('v1/journeys/{journey}/simulate', [JourneysController::class, 'simulate']);
        $router->post('v1/journeys/{journey}/run', [JourneysController::class, 'run']);

        $router->get('v1/journey-runs', [JourneysController::class, 'runs']);
        $router->get('v1/journey-runs/{run}', [JourneysController::class, 'runDetail']);
        $router->post('v1/journey-runs/{run}/resume', [JourneysController::class, 'resumeRun']);

        // ------------------------------------------------------------------
        // Business Outcomes.
        // ------------------------------------------------------------------
        $router->get('v1/outcomes', [OutcomesController::class, 'show']);
        $router->get('v1/outcomes/evidence', [OutcomesController::class, 'evidence']);
        $router->get('v1/outcomes/export', [OutcomesController::class, 'export']);
        $router->post('v1/outcomes/links', [OutcomesController::class, 'link']);

        // ------------------------------------------------------------------
        // Channels & Trust.
        // ------------------------------------------------------------------
        $router->get('v1/channels', [ChannelsController::class, 'index']);
        $router->post('v1/channels', [ChannelsController::class, 'create']);
        $router->put('v1/channels/{connection}', [ChannelsController::class, 'update']);
        $router->post('v1/channels/{connection}/health-check', [ChannelsController::class, 'healthCheck']);

        // Consent and suppression. Fixed paths before {uuid} paths.
        $router->get('v1/consents', [ConsentController::class, 'index']);
        $router->post('v1/consents', [ConsentController::class, 'record']);
        $router->get('v1/consents/history', [ConsentController::class, 'history']);
        $router->post('v1/consents/{consent}/withdraw', [ConsentController::class, 'withdraw']);

        $router->get('v1/suppressions', [ConsentController::class, 'suppressions']);
        $router->post('v1/suppressions', [ConsentController::class, 'suppress']);
        $router->post('v1/suppressions/{suppression}/release', [ConsentController::class, 'release']);

        // ------------------------------------------------------------------
        // Contacts browser — a live window onto Aicountly Contacts.
        // ------------------------------------------------------------------
        $router->get('v1/contacts', [ContactsController::class, 'index']);
        $router->get('v1/contacts/{contact}', [ContactsController::class, 'show']);
    }
}
