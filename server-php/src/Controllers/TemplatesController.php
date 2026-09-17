<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Domain\TemplateService;
use Aicountly\Api\Http;

final class TemplatesController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter('messaging.templates.view');

        $params = Http::listParams(['updated_at', 'name'], 'updated_at');
        $result = TemplateService::index($ctx, [
            'channel' => Http::param('channel', ''),
            'status'  => Http::param('status', ''),
            'q'       => $params['q'],
        ], $params['limit'], $params['offset']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset'], [
            'status_note' => 'Provider approval status is what the provider reported, with the time it was read. '
                . 'Messaging does not decide it.',
        ]);
    }

    public static function show(string $templateUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.templates.view');

        $template = TemplateService::find($ctx, $templateUuid);
        if ($template === null) {
            Http::notFound('That template could not be found.');
        }

        Http::data(['template' => $template]);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter('messaging.templates.manage');

        $result = TemplateService::save($ctx, $auth, Http::body());
        if (!$result['ok']) {
            self::fail($result['code'], $result['detail']);
        }

        Audit::record($ctx, $auth, 'template.saved', 'template', $result['template_uuid'], null, [
            'version' => $result['version'],
        ]);

        Http::data([
            'template' => TemplateService::find($ctx, (string) $result['template_uuid']),
            'detail'   => $result['detail'] . ' It cannot be sent until the provider approves it.',
        ], 201);
    }

    public static function update(string $templateUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.templates.manage');

        $result = TemplateService::save($ctx, $auth, ['template_uuid' => $templateUuid] + Http::body());
        if (!$result['ok']) {
            self::fail($result['code'], $result['detail']);
        }

        Audit::record($ctx, $auth, 'template.saved', 'template', $templateUuid, null, ['version' => $result['version']]);

        Http::data([
            'template' => TemplateService::find($ctx, $templateUuid),
            'detail'   => $result['detail'],
        ]);
    }

    public static function submit(string $templateUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.templates.submit');

        $version = Http::intParam('version', 0) ?? 0;
        $language = (string) (Http::param('language') ?? 'en') ?: 'en';

        if ($version <= 0) {
            Http::validationFailed('Which version should be submitted? (version is required.)');
        }

        $result = TemplateService::submit($ctx, $auth, $templateUuid, $version, $language);
        if (!$result['ok']) {
            self::fail($result['code'], $result['detail']);
        }

        Audit::record($ctx, $auth, 'template.submitted', 'template', $templateUuid, null, [
            'version'  => $version,
            'language' => $language,
        ]);

        Http::data([
            'template' => TemplateService::find($ctx, $templateUuid),
            'detail'   => $result['detail'],
        ]);
    }

    /**
     * Record what the provider says about a version.
     *
     * Called by an administrator after checking the provider console, or by the
     * provider's own webhook. It records an answer; it does not decide one.
     */
    public static function recordProviderStatus(string $templateUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.templates.manage');

        $body = Http::body();
        $version = (int) ($body['version'] ?? 0);
        $language = (string) ($body['language'] ?? 'en') ?: 'en';
        $status = (string) ($body['provider_status'] ?? '');

        if ($version <= 0 || $status === '') {
            Http::validationFailed('version and provider_status are required.');
        }

        TemplateService::recordProviderStatus(
            $ctx,
            $templateUuid,
            $version,
            $language,
            $status,
            isset($body['provider_template_id']) ? (string) $body['provider_template_id'] : null,
            isset($body['rejection_reason']) ? (string) $body['rejection_reason'] : null,
        );

        Audit::record($ctx, $auth, 'template.provider_status_recorded', 'template', $templateUuid, null, [
            'version'         => $version,
            'language'        => $language,
            'provider_status' => $status,
        ]);

        Http::data([
            'template' => TemplateService::find($ctx, $templateUuid),
            'detail'   => 'Recorded as reported by the provider, with the time it was read.',
        ]);
    }
}
