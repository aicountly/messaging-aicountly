<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\CrossServiceCallContext;
use Aicountly\Api\Env;
use Aicountly\Api\Http;

/**
 * Base for every outbound call to another AICOUNTLY product.
 *
 * THE RULE THIS CLASS EXISTS TO KEEP: authoritative data owned by another
 * product is READ LIVE, here, on the request that needs it. It is never copied
 * into this product's database, never refreshed by a cron, never mirrored into
 * a "cache" table. A short-lived in-request memo (see $memo) is the only thing
 * that survives a call, and it dies with the process.
 *
 * That rule is sharpest for Books. A conversation row here holds an invoice
 * REFERENCE and nothing else about the invoice — not the amount, not the due
 * date, not whether it is still open. Every screen that shows what a customer
 * owes reads it from Books on that request, and every payment reminder re-reads
 * it in the moment before dispatch, because a copied balance is a balance that
 * stays wrong after the customer pays.
 *
 * A message this product already sent is the one thing that looks like an
 * exception and is not. "Invoice INV-2048 for ₹18,500 is overdue" is stored,
 * because it is a true record of what was said on the day it was said. It is
 * historical correspondence, not an authoritative copy of the invoice, and
 * nothing reads it to answer "what is the balance now?".
 *
 * Two timeout budgets, both with a connect bound, because a product that is up
 * but not accepting — every child blocked waiting on another pool — is held
 * only by CURLOPT_CONNECTTIMEOUT:
 *
 *   optional  connect 2s / total 6s   a panel degrades and says so
 *   required  connect 3s / total 20s  a write the user is waiting on
 *
 * EVERY RESULT CARRIES ITS PROVENANCE. `source`, `fetched_at`, `state` and
 * `correlation_id` come back on success and on failure alike, because the
 * Unified Inbox has to say "Books, read 4 seconds ago" next to a balance and
 * "Pay is not connected" in place of a payment link. A client that returned a
 * bare array would make that impossible to do honestly.
 *
 * The five states are the brief's: ready | pending | unavailable | forbidden |
 * unsupported. They are different answers to different questions and a UI that
 * collapses them shows "no data" where it should show "you may not see this".
 */
abstract class ApiClient
{
    protected const CONNECT_TIMEOUT_OPTIONAL = 2;
    protected const TOTAL_TIMEOUT_OPTIONAL   = 6;
    protected const CONNECT_TIMEOUT_REQUIRED = 3;
    protected const TOTAL_TIMEOUT_REQUIRED   = 20;

    /**
     * Per-request memo of GET responses, keyed by method+url.
     *
     * This is deliberately process-local and dies with the request. It exists so
     * one screen that needs the same item list in three places costs one call,
     * not three — never so a later request can skip asking. Nothing here is
     * written to disk or to the database.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

    /** Product name this client talks to: calendar | contacts | manage | crm | pay | … */
    abstract public function service(): string;

    /** Production origin, used when nothing more specific is configured. */
    abstract protected function productionBase(): string;

    /** Sandbox origin. */
    abstract protected function sandboxBase(): string;

    /** Environment variable that overrides the derived base, e.g. BOOKS_API_BASE. */
    abstract protected function baseEnvKey(): string;

    /** This product's own name, sent so the callee will not call us back inside our own request. */
    protected function selfName(): string
    {
        return Env::get('APP_PRODUCT_KEY', 'messaging');
    }

    /**
     * Whether this deployment has this integration at all.
     *
     * Overridden by every subclass to consult its Features flag. The default is
     * true for the handful of products Messaging cannot work without.
     */
    public function configured(): bool
    {
        return true;
    }

    /**
     * The one sentence a disabled panel shows. Overridden per product so the
     * wording names the product rather than saying "unavailable" everywhere.
     */
    public function unavailableMessage(): string
    {
        return 'Aicountly ' . ucfirst($this->service()) . ' is not connected for this deployment.';
    }

    /**
     * The answer for an integration this deployment has not configured.
     *
     * `pending` and not `unavailable`: nothing is broken, somebody has simply
     * not finished setting it up, and those two need different words in front
     * of a user and different actions from an administrator.
     *
     * @return array{ok:bool, status:int, body:?array, error:?string, state:string, source:string, fetched_at:string, retryable:bool, message:string, correlation_id:string}
     */
    public function pendingResult(): array
    {
        return $this->envelope(false, 0, null, 'integration_pending', 'pending', false, $this->unavailableMessage());
    }

    /**
     * Wrap a raw outcome with its provenance.
     *
     * @param array<string, mixed>|null $body
     * @return array{ok:bool, status:int, body:?array, error:?string, state:string, source:string, fetched_at:string, retryable:bool, message:string, correlation_id:string}
     */
    protected function envelope(
        bool $ok,
        int $status,
        ?array $body,
        ?string $error,
        string $state,
        bool $retryable,
        string $message = '',
    ): array {
        return [
            'ok'             => $ok,
            'status'         => $status,
            'body'           => $body,
            'error'          => $error,
            'state'          => $state,
            'source'         => $this->service(),
            'fetched_at'     => gmdate('c'),
            'retryable'      => $retryable,
            'message'        => $message,
            'correlation_id' => $this->correlationId,
        ];
    }

    /**
     * One id per inbound request, put on every outbound call and returned in
     * every envelope.
     *
     * When an agent says "the balance panel said unavailable at 11:42", this is
     * what turns that into the log lines for the call that failed — across two
     * products, without either of them logging the identifiers themselves.
     */
    private string $correlationId;

    public function __construct()
    {
        $existing = Http::header('X-Correlation-Id');
        $this->correlationId = $existing !== '' && preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $existing) === 1
            ? $existing
            : bin2hex(random_bytes(8));
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Where this product's sibling lives.
     *
     * Derived from our own hostname so sandbox talks to sandbox without a second
     * set of environment variables to keep in step — an explicit env override
     * still wins, for local development and for a one-off cutover.
     */
    public function base(): string
    {
        $configured = Env::get($this->baseEnvKey());
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = explode(':', preg_replace('/^www\./', '', $host) ?? '')[0];

        if (str_contains($host, '.gh.aicountly.com') || str_starts_with($host, 'gh-') || $host === '' || str_contains($host, 'localhost') || str_starts_with($host, '127.')) {
            return $this->sandboxBase();
        }

        return $this->productionBase();
    }

    /** The API root. A configured base may already include /api, and a local spark origin serves it at the root. */
    public function apiRoot(): string
    {
        $base = $this->base();
        if (preg_match('#/api$#', $base) === 1 || preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#', $base) === 1) {
            return $base;
        }

        return $base . '/api';
    }

    /**
     * One call to the other product.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers extra headers (auth is added by the caller through withSession)
     * @return array{ok:bool, status:int, body:?array, error:?string, state:string, source:string, fetched_at:string, retryable:bool, message:string, correlation_id:string}
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = [], bool $required = false): array
    {
        $method = strtoupper($method);

        // RE-ENTRY GUARD. If the product we are about to call is the one whose
        // request we are serving, its worker is already blocked on our response.
        // Calling it now parks a second one of its workers on a request that is
        // waiting on it. See CrossServiceCallContext.
        if (CrossServiceCallContext::isInboundFrom($this->service())) {
            $this->log('suppressed', $path, 0, 0.0, 'inbound_from_' . $this->service());

            return $this->envelope(
                false,
                0,
                null,
                $this->service() . '_reentrant_call_refused',
                'unavailable',
                false,
                'Context from ' . $this->service() . ' is not shown here, because ' . $this->service()
                    . ' is waiting on this request.',
            );
        }

        $url = $this->apiRoot() . '/' . ltrim($path, '/');
        $memoKey = $method . ' ' . $url . ' ' . ($headers['Authorization'] ?? '');
        if ($method === 'GET' && isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $startedAt = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            return $this->envelope(false, 0, null, 'curl_init_failed', 'unavailable', true, 'Could not start the request.');
        }

        $wire = ['Accept: application/json'];
        if ($body !== null) {
            $wire[] = 'Content-Type: application/json';
        }
        // Name ourselves, so the callee suppresses its own calls back to us.
        $wire[] = CrossServiceCallContext::HEADER . ': ' . $this->selfName();
        $wire[] = 'X-Source-App: ' . $this->selfName();
        $wire[] = 'X-Correlation-Id: ' . $this->correlationId;
        // No stored response, anywhere along the path.
        $wire[] = 'Cache-Control: no-store';
        foreach ($headers as $name => $value) {
            if ($value !== '') {
                $wire[] = $name . ': ' . $value;
            }
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $wire,
            CURLOPT_CONNECTTIMEOUT => $required ? self::CONNECT_TIMEOUT_REQUIRED : self::CONNECT_TIMEOUT_OPTIONAL,
            CURLOPT_TIMEOUT        => $required ? self::TOTAL_TIMEOUT_REQUIRED : self::TOTAL_TIMEOUT_OPTIONAL,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($ch);
        curl_close($ch);

        $ms = (microtime(true) - $startedAt) * 1000;

        if ($raw === false || $status === 0) {
            $this->log('failed', $path, $status, $ms, $transportError !== '' ? 'transport' : 'no_response');

            return $this->envelope(
                false,
                0,
                null,
                $transportError !== '' ? 'transport_error' : 'unreachable',
                'unavailable',
                true,
                'Aicountly ' . ucfirst($this->service()) . ' could not be reached.',
            );
        }

        $decoded = json_decode((string) $raw, true);
        $body = is_array($decoded) ? $decoded : null;

        if ($status >= 200 && $status < 300) {
            $result = $this->envelope(true, $status, $body, null, 'ready', false);
        } else {
            $message = is_array($decoded)
                ? (string) ($decoded['error']['message'] ?? $decoded['message'] ?? 'HTTP ' . $status)
                : 'HTTP ' . $status;

            // 401/403 is NOT "unavailable". The user simply may not see this
            // over there, and the panel must say so rather than implying an
            // outage — or, worse, retrying.
            [$state, $retryable] = match (true) {
                $status === 401, $status === 403 => ['forbidden', false],
                $status === 404                  => ['unsupported', false],
                $status === 429                  => ['unavailable', true],
                $status >= 500                   => ['unavailable', true],
                default                          => ['unavailable', false],
            };

            $result = $this->envelope(
                false,
                $status,
                $body,
                is_array($decoded) ? (string) ($decoded['error']['code'] ?? 'http_' . $status) : 'http_' . $status,
                $state,
                $retryable,
                $state === 'forbidden'
                    ? 'You do not have permission to see this in Aicountly ' . ucfirst($this->service()) . '.'
                    : $message,
            );
            $this->log('error', $path, $status, $ms, null);
        }

        if ($method === 'GET') {
            $this->memo[$memoKey] = $result;
        }

        return $result;
    }

    /**
     * Log the path only — never the query string, which carries identifiers, and
     * never tokens or response bodies. A fast success is silent: these run on
     * every company-scoped request and logging each one buries the failures the
     * log exists to surface.
     */
    private function log(string $outcome, string $path, int $status, float $ms, ?string $reason): void
    {
        $operation = explode('?', ltrim($path, '/'))[0];
        error_log(sprintf(
            '[cross-service] service=%s outcome=%s operation=%s status=%d ms=%d cid=%s%s',
            $this->service(),
            $outcome,
            $operation,
            $status,
            (int) $ms,
            $this->correlationId,
            $reason !== null ? ' reason=' . $reason : '',
        ));
    }

    /** `?a=1&b=2` from a map, dropping nulls and empty strings. */
    protected static function query(array $params): string
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $clean[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        }

        return $clean === [] ? '' : '?' . http_build_query($clean);
    }
}
