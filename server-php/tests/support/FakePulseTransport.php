<?php

declare(strict_types=1);

namespace Aicountly\Api\Tests;

use Aicountly\Api\Ai\PulseAiClient;

/**
 * AI Pulse that is not AI Pulse.
 *
 * PulseAiClient takes its transport as a constructor argument, so the tests
 * hand it this instead of curl: no network, and every request it would have
 * sent is recorded — method, URL, headers and the decoded body — for the test
 * to assert on. Answers are queued in order; the last one repeats.
 *
 * The HTTP suite exercises the real transport against tests/stub/router.php;
 * this is for everything that is about WHAT is sent and how an answer is read.
 */
final class FakePulseTransport
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: mixed, timeout: float, connect: float}> */
    public array $requests = [];

    /** @var list<array{status: int, body: ?string, error: ?string}> */
    private array $answers;

    /** @param array{status: int, body: ?string, error: ?string} ...$answers */
    public function __construct(array ...$answers)
    {
        $this->answers = $answers;
    }

    /** @param list<string> $headers */
    public function __invoke(string $method, string $url, array $headers, ?string $body, float $timeout, float $connect): array
    {
        $this->requests[] = [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body === null ? null : json_decode($body, true),
            'timeout' => $timeout,
            'connect' => $connect,
        ];

        if ($this->answers === []) {
            return ['status' => 500, 'body' => '{"status":0,"code":"no_answer_queued"}', 'error' => null];
        }

        return count($this->answers) > 1 ? array_shift($this->answers) : $this->answers[0];
    }

    /** A client for product "messaging" that talks to this fake. */
    public function client(string $origin = 'https://pulse.test'): PulseAiClient
    {
        return new PulseAiClient(PulseAiClient::PRODUCT, $origin, 20.0, 4.0, $this);
    }

    /** @return array{method: string, url: string, headers: list<string>, body: mixed, timeout: float, connect: float} */
    public function last(): array
    {
        if ($this->requests === []) {
            throw new \RuntimeException('No request reached AI Pulse.');
        }

        return $this->requests[count($this->requests) - 1];
    }

    /** A header of the last request, by name, or null. */
    public function header(string $name): ?string
    {
        foreach ($this->last()['headers'] as $line) {
            [$key, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    // -- Answers -------------------------------------------------------------

    /** @param array<string, mixed> $data */
    public static function ok(array $data): array
    {
        return ['status' => 200, 'body' => (string) json_encode(['status' => 1, 'data' => $data]), 'error' => null];
    }

    /**
     * A generate answer, shaped like Pulse's (AI_GATEWAY.md §6).
     *
     * @param array<string, mixed> $overrides
     */
    public static function generated(string $text, array $overrides = []): array
    {
        return self::ok($overrides + [
            'id'          => '5d7c1a3e-9b2f-4c61-8e0a-3f4b5c6d7e8f',
            'text'        => $text,
            'json'        => null,
            'tool_calls'  => [],
            'stop_reason' => 'end',
            'model'       => 'stub-flash',
            'provider'    => 'google',
            'tier'        => 'economy',
            'usage'       => ['input_tokens' => 812, 'output_tokens' => 64, 'cached_input_tokens' => 0],
            'cost_usd'    => 0.0001,
            'latency_ms'  => 420,
            'attempts'    => 1,
            'replayed'    => false,
            'cached'      => false,
        ]);
    }

    /** @param array<string, mixed> $json */
    public static function generatedJson(array $json, array $overrides = []): array
    {
        return self::generated((string) json_encode($json), $overrides + ['json' => $json]);
    }

    /** An error, shaped like Pulse's (AI_GATEWAY.md §7). */
    public static function error(int $status, string $code, bool $retryable = false, array $detail = []): array
    {
        $body = ['status' => 0, 'code' => $code, 'message' => 'Pulse says ' . $code . '.', 'retryable' => $retryable];
        if ($detail !== []) {
            $body['detail'] = $detail;
        }

        return ['status' => $status, 'body' => (string) json_encode($body), 'error' => null];
    }

    /** Pulse did not answer at all: 'unreachable' or 'timeout'. */
    public static function down(string $error = 'unreachable'): array
    {
        return ['status' => 0, 'body' => null, 'error' => $error];
    }

    /** @param array<string, mixed> $overrides */
    public static function status(bool $available = true, array $overrides = []): array
    {
        return self::ok($overrides + [
            'enabled'   => true,
            'available' => $available,
            'reason'    => $available ? null : 'module_not_bound',
            'tiers'     => ['economy' => $available, 'strong' => false],
            'images'    => false,
            'realtime'  => false,
            'caller'    => ['product' => 'messaging', 'auth' => 'user'],
        ]);
    }
}
