<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Domain\JourneyDefinition;
use Aicountly\Api\Domain\JourneyEngine;
use Aicountly\Api\Domain\JourneyService;
use Aicountly\Api\Domain\JourneySimulator;
use Aicountly\Api\Domain\TemplateService;
use Aicountly\Api\Http;
use Aicountly\Api\Permissions;

final class JourneysController extends Controller
{
    public static function index(): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.view');

        $params = Http::listParams(['updated_at', 'name'], 'updated_at');
        $result = JourneyService::index($ctx, [
            'status' => Http::param('status', ''),
            'kind'   => Http::param('kind', ''),
        ], $params['limit'], $params['offset']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset'], [
            'starters' => array_map(
                static fn (array $s, string $key) => [
                    'kind'        => $key,
                    'name'        => $s['name'],
                    'description' => $s['description'],
                ],
                JourneyDefinition::starters(),
                array_keys(JourneyDefinition::starters()),
            ),
            'node_types' => JourneyDefinition::NODE_TYPES,
            'sources'    => JourneyDefinition::SOURCES,
        ]);
    }

    public static function show(string $journeyUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.view');

        $version = Http::intParam('version');
        $journey = JourneyService::find($ctx, $journeyUuid, $version);
        if ($journey === null) {
            Http::notFound('That journey could not be found.');
        }

        Http::data(['journey' => $journey]);
    }

    public static function create(): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.manage');

        $body = Http::body();

        // Starting from a shipped starter. These are starting points a user
        // reviews, not journeys that run on install.
        $starter = (string) ($body['starter'] ?? '');
        if ($starter !== '') {
            $starters = JourneyDefinition::starters();
            if (!isset($starters[$starter])) {
                Http::validationFailed('Unknown starter "' . $starter . '".');
            }
            $body = [
                'name'        => (string) ($body['name'] ?? $starters[$starter]['name']),
                'description' => $starters[$starter]['description'],
                'kind'        => $starter,
                'definition'  => ['entry' => $starters[$starter]['entry'], 'nodes' => $starters[$starter]['nodes']],
            ];
        }

        $result = JourneyService::save($ctx, $auth, $body);
        if (!$result['ok']) {
            self::fail($result['code'], $result['detail']);
        }

        Audit::record($ctx, $auth, 'journey.saved', 'journey', $result['journey_uuid'], null, [
            'version' => $result['version'],
        ]);

        Http::data([
            'journey'    => JourneyService::find($ctx, (string) $result['journey_uuid'], $result['version']),
            'validation' => $result['validation'],
            'detail'     => $result['detail'],
        ], 201);
    }

    public static function update(string $journeyUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.manage');

        $result = JourneyService::save($ctx, $auth, ['journey_uuid' => $journeyUuid] + Http::body());
        if (!$result['ok']) {
            self::fail($result['code'], $result['detail']);
        }

        Audit::record($ctx, $auth, 'journey.saved', 'journey', $journeyUuid, null, ['version' => $result['version']]);

        Http::data([
            'journey'    => JourneyService::find($ctx, $journeyUuid, $result['version']),
            'validation' => $result['validation'],
            'detail'     => $result['detail'],
        ]);
    }

    /** Validate without saving, for the editor's live panel. */
    public static function validate(): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.view');

        $definition = Http::body()['definition'] ?? [];
        if (!is_array($definition)) {
            Http::validationFailed('A definition is required.');
        }

        Http::data(['validation' => JourneyDefinition::validate($ctx, $definition)]);
    }

    public static function publish(string $journeyUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.publish');

        $version = Http::intParam('version', 0) ?? 0;
        if ($version <= 0) {
            $journey = JourneyService::find($ctx, $journeyUuid);
            $version = (int) ($journey['draft_version'] ?? 0);
        }
        if ($version <= 0) {
            Http::validationFailed('Which version should be published?');
        }

        $result = JourneyService::publish($ctx, $auth, $journeyUuid, $version);

        if (!$result['ok']) {
            // The validation detail travels with the refusal, so the editor can
            // point at the steps that need fixing.
            Http::json(422, [
                'error' => [
                    'code'    => $result['code'],
                    'message' => $result['detail'],
                    'details' => ['validation' => $result['validation']],
                ],
                'message' => $result['detail'],
            ]);
        }

        Audit::record($ctx, $auth, 'journey.published', 'journey', $journeyUuid, null, ['version' => $version]);

        Http::data([
            'journey'    => JourneyService::find($ctx, $journeyUuid, $version),
            'validation' => $result['validation'],
            'detail'     => $result['detail'],
        ]);
    }

    public static function setStatus(string $journeyUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.publish');

        $status = (string) (Http::param('status') ?? '');
        if (!JourneyService::setStatus($ctx, $auth, $journeyUuid, $status)) {
            self::fail(
                'validation_failed',
                'That status could not be set. A journey can only be published if it has a published version.',
            );
        }

        Audit::record($ctx, $auth, 'journey.status_changed', 'journey', $journeyUuid, null, ['status' => $status]);

        Http::data(['journey' => JourneyService::find($ctx, $journeyUuid)]);
    }

    /**
     * Simulate. Sends nothing.
     */
    public static function simulate(string $journeyUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.simulate');

        $body = Http::body();
        $result = JourneySimulator::run($ctx, $auth, $journeyUuid, [
            'data_source' => (string) ($body['data_source'] ?? 'live_readonly'),
            'count'       => (int) ($body['count'] ?? 25),
        ]);

        if (!($result['ok'] ?? false)) {
            Http::notFound((string) ($result['message'] ?? 'That journey could not be simulated.'));
        }

        Audit::record($ctx, $auth, 'journey.simulated', 'journey', $journeyUuid, null, [
            'data_source' => $result['data_source'],
            'considered'  => $result['subjects']['considered'],
        ]);

        Http::data($result);
    }

    /**
     * Run a journey once, for a named subject.
     *
     * The manual path, for when an event source is unavailable or somebody
     * wants to chase one specific invoice.
     */
    public static function run(string $journeyUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.publish');

        $body = Http::body();

        $result = JourneyEngine::start($ctx, $auth, $journeyUuid, [
            'subject_product'   => (string) ($body['subject_product'] ?? ''),
            'subject_ref'       => (string) ($body['subject_ref'] ?? ''),
            'contact_uuid'      => (string) ($body['contact_uuid'] ?? ''),
            'conversation_uuid' => (string) ($body['conversation_uuid'] ?? ''),
            'trigger_key'       => (string) ($body['trigger_key'] ?? ''),
        ], 'live', 'manual');

        if (!$result['ok']) {
            self::fail($result['code'], $result['detail']);
        }

        Audit::record($ctx, $auth, 'journey.run_started', 'journey_run', $result['run_uuid'], null, [
            'journey_uuid' => $journeyUuid,
            'duplicate'    => $result['duplicate'],
        ]);

        Http::data([
            'run'       => JourneyService::run($ctx, (string) $result['run_uuid']),
            'duplicate' => $result['duplicate'],
            'detail'    => $result['detail'],
        ], $result['duplicate'] ? 200 : 201);
    }

    public static function runs(): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.view');

        $params = Http::listParams(['started_at'], 'started_at');
        $result = JourneyService::runs($ctx, [
            'journey_uuid' => Http::param('journey_uuid', ''),
            'status'       => Http::param('status', ''),
            'mode'         => Http::param('mode', ''),
        ], $params['limit'], $params['offset']);

        Http::list($result['rows'], $result['total'], $params['limit'], $params['offset']);
    }

    public static function runDetail(string $runUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.view');

        $run = JourneyService::run($ctx, $runUuid);
        if ($run === null) {
            Http::notFound('That run could not be found.');
        }

        Http::data([
            'run'  => $run,
            'note' => 'The steps below are what actually happened, with the reason for each decision and which '
                . 'product was consulted. The definition shown is the immutable published version this run '
                . 'executed, not the journey as it stands now.',
        ]);
    }

    /** Resume a run that was waiting. */
    public static function resumeRun(string $runUuid): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.publish');

        $result = JourneyEngine::resume($ctx, $auth, $runUuid);

        Audit::record($ctx, $auth, 'journey.run_resumed', 'journey_run', $runUuid, null, ['status' => $result['status']]);

        Http::data([
            'run'    => JourneyService::run($ctx, $runUuid),
            'detail' => $result['detail'],
        ]);
    }

    /**
     * Turn an instruction into a PROPOSED journey.
     *
     * ## What this does not do
     *
     * It does not create a journey, publish one, or send anything. It maps the
     * instruction onto a starter and this company's own approved templates,
     * and returns a definition for somebody to review. The natural-language
     * input cannot reach past the caller's permissions — it selects from a
     * closed vocabulary of OUR starters and OUR templates, and anything outside
     * that is discarded in AiClient::interpret().
     */
    public static function propose(): void
    {
        [$auth, $ctx] = self::enter('messaging.journeys.manage');

        $instruction = trim((string) (Http::param('instruction') ?? ''));
        if ($instruction === '') {
            Http::validationFailed('Describe what the journey should do.');
        }
        if (!AiClient::isAvailable()) {
            Http::error(503, 'ai_unavailable', 'The assistant is not configured, so an instruction cannot be '
                . 'interpreted. You can still build a journey from one of the starters.', ['retryable' => false]);
        }

        $starters = JourneyDefinition::starters();

        // The CLOSED vocabulary. A model cannot name a journey kind, a channel
        // or a template that this company does not have.
        $templates = Db::all(
            'SELECT t.template_uuid, t.name, t.channel
             FROM messaging_templates t
             WHERE t.cmp_id = :cmp AND t.is_active = TRUE AND t.active_version IS NOT NULL
             ORDER BY t.name LIMIT 50',
            ['cmp' => $ctx->cmpId],
        );

        $channels = Db::all(
            'SELECT DISTINCT channel FROM messaging_channel_connections
             WHERE cmp_id = :cmp AND is_active = TRUE AND status = :connected',
            ['cmp' => $ctx->cmpId, 'connected' => 'connected'],
        );

        $vocabulary = [
            'kind'     => array_keys($starters),
            'channel'  => $channels === [] ? [] : array_map(static fn (array $r) => (string) $r['channel'], $channels),
            'template' => $templates === [] ? [] : array_map(static fn (array $r) => (string) $r['name'], $templates),
            'language' => array_keys(\Aicountly\Api\Ai\DraftAssistant::supportedLanguages($ctx)),
        ];

        $interpreted = AiClient::interpret(
            $instruction,
            array_filter($vocabulary, static fn (array $values) => $values !== []),
            'Choose the journey kind, channel, template and language this instruction describes.',
        );

        if (!$interpreted['ok']) {
            Http::error(503, 'ai_unavailable', (string) $interpreted['error']);
        }

        $kind = $interpreted['values']['kind'] ?? '';
        if ($kind === '' || !isset($starters[$kind])) {
            Http::data([
                'proposed'   => null,
                'message'    => 'That instruction did not match one of the journey kinds this product supports. '
                    . 'The supported kinds are: ' . implode(', ', array_keys($starters)) . '.',
                'understood' => $interpreted['values'],
            ]);
        }

        $starter = $starters[$kind];
        $definition = ['entry' => $starter['entry'], 'nodes' => $starter['nodes']];

        // Apply the interpreted choices onto the starter's nodes. Only values
        // the vocabulary allowed can land here.
        $chosenTemplate = null;
        if (isset($interpreted['values']['template'])) {
            foreach ($templates as $template) {
                if ((string) $template['name'] === $interpreted['values']['template']) {
                    $chosenTemplate = $template;
                    break;
                }
            }
        }

        foreach ($definition['nodes'] as $index => $node) {
            if ((string) $node['type'] === 'draft') {
                if ($chosenTemplate !== null) {
                    $definition['nodes'][$index]['template_uuid'] = (string) $chosenTemplate['template_uuid'];
                }
                if (isset($interpreted['values']['language'])) {
                    $definition['nodes'][$index]['language'] = $interpreted['values']['language'];
                }
            }
            if ((string) $node['type'] === 'send' && isset($interpreted['values']['channel'])) {
                $definition['nodes'][$index]['channel'] = $interpreted['values']['channel'];
            }
        }

        $validation = JourneyDefinition::validate($ctx, $definition);

        Http::data([
            'proposed' => [
                'name'        => $starter['name'],
                'description' => $starter['description'],
                'kind'        => $kind,
                'definition'  => $definition,
            ],
            'understood'  => $interpreted['values'],
            'validation'  => $validation,
            // The honest framing. Nothing has been created.
            'created'     => false,
            'kind_note'   => 'This is a PROPOSAL assembled from one of this product\'s starters and your own '
                . 'approved templates. Nothing has been created or published. Review every step, then save it.',
            'requires_review' => true,
            'data_sources' => array_values(array_unique(array_filter(array_map(
                static fn (array $n) => (string) ($n['source'] ?? ''),
                $definition['nodes'],
            )))),
            'approval_required' => $validation['summary']['requires_approval'] ?? false,
        ]);
    }
}
