<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Ai\ConsoleCredentials;
use Aicountly\Api\Ai\DraftAssistant;
use Aicountly\Api\Domain\ConversationService;
use Aicountly\Api\Http;

/**
 * The inbox assistant's endpoints.
 *
 * Every one of them returns a SUGGESTION. None of them sends anything, changes
 * a conversation's state, or writes a message. The draft endpoints return text
 * the caller must then save as a draft and a human must then approve — three
 * separate steps, deliberately, because collapsing them is how an AI ends up
 * messaging a customer unreviewed.
 */
final class AiController extends Controller
{
    public static function status(): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $status = ConsoleCredentials::status();
        $settings = \Aicountly\Api\Domain\Settings::for($ctx);

        Http::data([
            'available'  => AiClient::isAvailable(),
            'provider'   => $status['provider'],
            'model'      => $status['model'],
            'reason'     => $status['reason'],
            // Only an administrator gets the configuration hint, because it
            // names environment variables.
            'admin_hint' => \Aicountly\Api\Permissions::allows($ctx, $auth, 'messaging.ai.manage')
                ? $status['admin_hint']
                : null,
            'permitted'  => [
                'draft'     => (bool) $settings['ai_draft_allowed'],
                'translate' => (bool) $settings['ai_translate_allowed'],
                'summarise' => (bool) $settings['ai_summarise_allowed'],
                'suggest'   => (bool) $settings['ai_suggest_allowed'],
                // Reported, and false by default. A UI that showed this as on
                // when it is off would be the worst possible mistake here.
                'autosend'  => (bool) $settings['ai_autosend_allowed'],
            ],
            'languages'  => DraftAssistant::supportedLanguages($ctx),
            'policy_note' => 'Drafts always require a human to review and send them. Autonomous sending is off '
                . 'unless somebody has explicitly enabled it in Channels & Trust.',
        ]);
    }

    public static function draft(): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.reply');

        $conversation = self::conversation($ctx, $auth);
        $body = Http::body();

        $result = DraftAssistant::draft($ctx, $auth, $conversation, [
            'tone'     => (string) ($body['tone'] ?? 'neutral'),
            'language' => (string) ($body['language'] ?? ''),
        ]);

        if (!($result['ok'] ?? false)) {
            // 503 rather than 500: the assistant is a dependency that can be
            // absent, and the inbox works without it.
            Http::error(
                ($result['disabled'] ?? false) ? 403 : 503,
                ($result['disabled'] ?? false) ? 'ai_disabled' : 'ai_unavailable',
                (string) $result['message'],
                ['retryable' => !($result['disabled'] ?? false)],
            );
        }

        Http::data($result);
    }

    public static function summarise(): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $conversation = self::conversation($ctx, $auth);
        $result = DraftAssistant::summarise($ctx, $auth, $conversation);

        if (!($result['ok'] ?? false)) {
            Http::error(
                ($result['disabled'] ?? false) ? 403 : 503,
                ($result['disabled'] ?? false) ? 'ai_disabled' : 'ai_unavailable',
                (string) $result['message'],
            );
        }

        Http::data($result);
    }

    public static function translate(): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.reply');

        $body = Http::body();
        $text = trim((string) ($body['text'] ?? ''));
        $target = (string) ($body['language'] ?? '');

        if ($text === '' || $target === '') {
            Http::validationFailed('Both text and a target language are required.');
        }

        $result = DraftAssistant::translate(
            $ctx,
            $auth,
            mb_substr($text, 0, 6000),
            $target,
            isset($body['conversation_uuid']) ? (string) $body['conversation_uuid'] : null,
        );

        if (!($result['ok'] ?? false)) {
            // A refused translation is a 422, not a 503: the model answered,
            // and the answer was rejected because it altered a commitment.
            Http::json(($result['disabled'] ?? false) ? 403 : 422, [
                'error' => [
                    'code'    => ($result['disabled'] ?? false) ? 'ai_disabled' : 'translation_rejected',
                    'message' => (string) $result['message'],
                    'details' => ['missing' => $result['missing'] ?? []],
                ],
                'message' => (string) $result['message'],
            ]);
        }

        Http::data($result);
    }

    /** Shorten, or adjust tone. */
    public static function rewrite(): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.reply');

        $body = Http::body();
        $text = trim((string) ($body['text'] ?? ''));
        $mode = (string) ($body['mode'] ?? 'shorten');

        if ($text === '') {
            Http::validationFailed('There is no text to rewrite.');
        }
        if (!in_array($mode, ['shorten', 'tone'], true)) {
            Http::validationFailed('Mode must be "shorten" or "tone".');
        }

        $result = DraftAssistant::rewrite(
            $ctx,
            $auth,
            mb_substr($text, 0, 6000),
            $mode,
            ['tone' => (string) ($body['tone'] ?? 'neutral')],
            isset($body['conversation_uuid']) ? (string) $body['conversation_uuid'] : null,
        );

        if (!($result['ok'] ?? false)) {
            Http::error(
                ($result['disabled'] ?? false) ? 403 : 503,
                ($result['disabled'] ?? false) ? 'ai_disabled' : 'ai_unavailable',
                (string) $result['message'],
            );
        }

        Http::data($result);
    }

    /** Intent, sentiment, urgency, and what is missing to answer. */
    public static function analyse(): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $conversation = self::conversation($ctx, $auth);
        $result = DraftAssistant::analyse($ctx, $auth, $conversation);

        if (!($result['ok'] ?? false)) {
            Http::error(
                ($result['disabled'] ?? false) ? 403 : 503,
                ($result['disabled'] ?? false) ? 'ai_disabled' : 'ai_unavailable',
                (string) $result['message'],
            );
        }

        // An accepted classification may be stored on the conversation, and it
        // is recorded as the AI's opinion rather than as a fact.
        if (\Aicountly\Api\Http::boolParam('apply') && ($result['intent'] ?? null) !== null) {
            \Aicountly\Api\Db::update(
                'messaging_conversations',
                [
                    'intent'        => (string) $result['intent'],
                    'intent_source' => 'ai',
                    'updated_at'    => \Aicountly\Api\Support\Clock::nowSql(),
                ],
                ['cmp_id' => $ctx->cmpId, 'conversation_uuid' => (string) $conversation['conversation_uuid']],
            );
            AiClient::markAccepted((string) $result['ai_run_uuid'], true);
        }

        Http::data($result);
    }

    /** Record that a human rejected a suggestion. The honest half of the metric. */
    public static function feedback(): void
    {
        [$auth, $ctx] = self::enter('messaging.conversations.view');

        $aiRunUuid = trim((string) (Http::param('ai_run_uuid') ?? ''));
        if ($aiRunUuid === '') {
            Http::validationFailed('ai_run_uuid is required.');
        }

        AiClient::markAccepted($aiRunUuid, Http::boolParam('accepted'));

        Http::data(['recorded' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function conversation(\Aicountly\Api\Context $ctx, \Aicountly\Api\Auth $auth): array
    {
        $conversationUuid = trim((string) (Http::param('conversation_uuid') ?? ''));
        if ($conversationUuid === '') {
            Http::validationFailed('conversation_uuid is required.');
        }

        $conversation = ConversationService::find($ctx, $conversationUuid);
        if ($conversation === null) {
            Http::notFound('That conversation could not be found.');
        }
        if (!ConversationService::mayView($ctx, $auth, $conversation)) {
            Http::forbidden('That conversation is assigned to somebody else.');
        }

        return $conversation;
    }
}
