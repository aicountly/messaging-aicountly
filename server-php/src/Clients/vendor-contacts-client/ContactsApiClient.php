<?php // source: contacts-react-app@6dc85d5 clients/php/ContactsApiClient.php

// source: contacts-react-app@<sha> clients/php/ContactsApiClient.php
//
// AICOUNTLY Contacts — canonical PHP client for contract v1 (docs/api/openapi.yaml).
// No framework, ext-curl + ext-json only. Consumers VENDOR a copy at
// <consumer>/…/vendor-contacts-client/ContactsApiClient.php, replace <sha> above with the
// contacts-react-app commit it was copied from, and run clients/drift-check.sh in CI.
//
// Status: 1.0.0 (2026-10-02). Every method below codes to the v1 contract and is exercised
// against the real server by server-php/tests/Integration/ContractConformanceTest.php:
// list/get/lookup (meta.matchCount)/create/find-or-create/from-chat-invite, PATCH + If-Match
// (412), archive/restore/delete, resolve/resolve-many/changes, 409 contact_merged, counts,
// company list/get/lookup/create/find-or-create/by-reference/references/archive/counts,
// strict allow-lists (400 unsupported_parameter / validation_failed), Delegation tokens and
// X-AIC-Service headers. Not covered by this client: imports, exports, categories and the
// provider screens (SPA-only surfaces; see docs/api/openapi.yaml).
//
// Rules this client enforces for every consumer (spec §4):
//   * only allowed query parameters are sent — anything else throws before the request;
//   * the {status, data, meta} / {status:0, error:{code,message,details}} envelope is parsed;
//   * 409 contact_merged → ContactMergedException carrying the survivor id (resolve() follows);
//   * 503 / 502 / 504 / timeout / network error → ContactsUnavailableException: "Contacts could
//     not answer" — NEVER "no such contact" and NEVER a logout;
//   * 401 → ContactsUnauthorizedException: re-check the session with the portal first; only the
//     portal's own definitive rejection ends a session;
//   * creates carry an Idempotency-Key (yours, or a generated one returned with the result);
//   * lookup returns matchCount — auto-attribute ONLY when matchCount === 1.

declare(strict_types=1);

namespace AicountlyContacts;

class ContactsApiException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message, $httpStatus);
    }
}

/** Contacts (or its database / the portal behind it) could not answer. Retry later; not "no contact". */
final class ContactsUnavailableException extends ContactsApiException
{
    public ?int $retryAfterSeconds = null;
}

/** The session is not valid for Contacts. Ask the portal before treating the user as signed out. */
final class ContactsUnauthorizedException extends ContactsApiException
{
}

/** 400 validation_failed / unsupported_parameter / empty_update. */
final class ContactsValidationException extends ContactsApiException
{
}

/** 409 contact_merged: the id was merged; `survivorId` is the final survivor. */
final class ContactMergedException extends ContactsApiException
{
    public function survivorId(): ?string
    {
        $into = $this->details['into'] ?? null;

        return is_string($into) && $into !== '' ? $into : null;
    }
}

/** 412 precondition_failed: someone else changed the contact; `currentVersion` says to what. */
final class ContactsPreconditionFailedException extends ContactsApiException
{
}

final class ContactsApiClient
{
    public const VERSION = '1.0.0';

    /** Query parameters each endpoint accepts (IMPLEMENTATION_SPEC §3.4). */
    public const ALLOWED = [
        'list'           => ['q', 'email', 'phone', 'type', 'category', 'source', 'tags', 'scope', 'page', 'per_page', 'include_archived', 'product', 'ecosystem_role', 'company_id', 'company', 'ids', 'sort'],
        'facets'         => ['q', 'email', 'phone', 'type', 'category', 'source', 'tags', 'scope', 'page', 'per_page', 'include_archived', 'product', 'ecosystem_role', 'company_id', 'company', 'ids', 'sort'],
        'counts'         => ['q', 'email', 'phone', 'type', 'category', 'source', 'tags', 'scope', 'include_archived', 'product', 'ecosystem_role', 'company_id', 'company', 'ids'],
        'company_counts' => ['q', 'email', 'phone', 'type', 'category', 'source', 'tags', 'scope', 'include_archived', 'product', 'ecosystem_role', 'company_id', 'company', 'ids', 'tax_id'],
        'export'         => ['q', 'email', 'phone', 'type', 'category', 'source', 'tags', 'scope', 'include_archived', 'product', 'ecosystem_role', 'company_id', 'company', 'ids', 'sort'],
        'lookup'         => ['email', 'phone'],
        'company_list'   => ['q', 'email', 'phone', 'type', 'category', 'source', 'tags', 'scope', 'page', 'per_page', 'include_archived', 'product', 'ecosystem_role', 'company_id', 'company', 'ids', 'sort', 'tax_id'],
        'company_lookup' => ['email', 'phone', 'tax_id'],
        'by_reference'   => ['product', 'ref_type', 'ref'],
        'changes'        => ['since', 'limit'],
    ];

    private ?string $sesKey = null;

    private ?string $delegationToken = null;

    private ?string $serviceProduct = null;

    private ?string $serviceKey = null;

    /** @var callable(string, string, list<string>, ?string): array{0:int,1:array<string,string>,2:string}|null */
    private $transport;

    /**
     * @param string $baseUrl e.g. https://contacts.aicountly.com/api (no trailing slash needed)
     * @param array{timeout?: int, connectTimeout?: int, userAgent?: string, transport?: callable} $options
     *        `transport` replaces curl (tests): fn(method, url, headerLines, ?body) => [status, headers, body]
     */
    public function __construct(private string $baseUrl, private array $options = [])
    {
        $this->baseUrl   = rtrim($baseUrl, '/');
        $this->transport = $options['transport'] ?? null;
    }

    /** Act as the signed-in user (their own my.aicountly ses_key). */
    public function withSession(string $sesKey): self
    {
        $c                  = clone $this;
        $c->sesKey          = $sesKey;
        $c->delegationToken = null;

        return $c;
    }

    /** Act through a delegation grant (worker), with the product's service credential (§3.9). */
    public function withDelegation(string $token, string $product, string $serviceKey): self
    {
        $c                  = clone $this;
        $c->delegationToken = $token;
        $c->sesKey          = null;
        $c->serviceProduct  = $product;
        $c->serviceKey      = $serviceKey;

        return $c;
    }

    /** Add the product's service credential to an interactive call (needed to issue a grant). */
    public function withServiceCredential(string $product, string $serviceKey): self
    {
        $c                 = clone $this;
        $c->serviceProduct = $product;
        $c->serviceKey     = $serviceKey;

        return $c;
    }

    // ── Personal book (the caller's own private contacts) ─────────────────────────────────

    /** @return array{data: list<array<string,mixed>>, meta: array<string,mixed>} */
    public function listContacts(array $params = []): array
    {
        return $this->page($this->request('GET', '/contacts', $this->query('list', $params)));
    }

    /**
     * True totals under the list filters: {total, archived, byCategory, byEcosystemRole, byType, bySource, byTag}.
     *
     * @return array<string,mixed>
     */
    public function contactCounts(array $params = []): array
    {
        return (array) $this->data($this->request('GET', '/contacts/counts', $this->query('counts', $params)));
    }

    /** @return array<string,mixed> */
    public function companyContactCounts(int|string $cmp, array $params = []): array
    {
        return (array) $this->data($this->request('GET', $this->c($cmp, '/contacts/counts'), $this->query('company_counts', $params)));
    }

    /** @return array<string,mixed>|null the contact, or null when it does not exist for this caller */
    public function getContact(string $id): ?array
    {
        return $this->dataOrNull('GET', '/contacts/' . rawurlencode($id));
    }

    /**
     * @param array{email?: string, phone?: string} $params exactly one of email or phone
     *
     * @return array{contact: array<string,mixed>|null, matchCount: int, attributable: bool}
     */
    public function lookup(array $params): array
    {
        return $this->lookupResult($this->request('GET', '/contacts/lookup', $this->query('lookup', $params)));
    }

    /** @return array{data: array<string,mixed>, idempotencyKey: string, replayed: bool} */
    public function createContact(array $contact, ?string $idempotencyKey = null): array
    {
        return $this->create('/contacts', $contact, $idempotencyKey);
    }

    /** @return array{data: array<string,mixed>, created: bool, idempotencyKey: string, replayed: bool} */
    public function findOrCreate(array $body, ?string $idempotencyKey = null): array
    {
        $r = $this->create('/contacts/find-or-create', $body, $idempotencyKey);

        return $r + ['created' => (bool) ($r['body']['created'] ?? false)];
    }

    /**
     * POST /contacts/from-chat-invite — always 200/201 with an explicit outcome.
     *
     * @return array{outcome: string, reason: ?string, data: array<string,mixed>|null}
     */
    public function fromChatInvite(array $body, ?string $idempotencyKey = null): array
    {
        $r = $this->request('POST', '/contacts/from-chat-invite', [], $body, ['Idempotency-Key' => $idempotencyKey ?? self::uuid4()]);

        return [
            'outcome' => (string) ($r['body']['outcome'] ?? ''),
            'reason'  => isset($r['body']['reason']) ? (string) $r['body']['reason'] : null,
            'data'    => is_array($r['body']['data'] ?? null) ? $r['body']['data'] : null,
        ];
    }

    /**
     * Partial update: only the keys present change; explicit null/[] clears (§3.5).
     *
     * @return array<string,mixed>
     */
    public function updateContact(string $id, array $patch, ?int $expectedVersion = null): array
    {
        return $this->patch('/contacts/' . rawurlencode($id), $patch, $expectedVersion);
    }

    public function archiveContact(string $id): void
    {
        $this->request('PATCH', '/contacts/' . rawurlencode($id) . '/archive');
    }

    /** @return array<string,mixed>|null */
    public function restoreContact(string $id): ?array
    {
        return $this->data($this->request('POST', '/contacts/' . rawurlencode($id) . '/restore'));
    }

    public function deleteContact(string $id): void
    {
        $this->request('DELETE', '/contacts/' . rawurlencode($id));
    }

    /**
     * Follow merges: {id, state: active|archived|merged|deleted, survivorId, contact?}.
     *
     * @return array<string,mixed>
     */
    public function resolve(string $id): array
    {
        return (array) $this->data($this->request('GET', '/contacts/' . rawurlencode($id) . '/resolve'));
    }

    /**
     * @param list<string> $ids at most 100
     *
     * @return list<array<string,mixed>>
     */
    public function resolveMany(array $ids): array
    {
        self::assertIds($ids);

        return (array) $this->data($this->request('POST', '/contacts/resolve', [], ['ids' => array_values($ids)]));
    }

    /** @return array{data: list<array<string,mixed>>, nextCursor: ?string} */
    public function changes(?string $since = null, int $limit = 100): array
    {
        return $this->changesFrom('/contacts/changes', $since, $limit);
    }

    // ── Company directory (/api/companies/{cmp}/…) — Manage-verified on every call ─────────

    /** @return array{data: list<array<string,mixed>>, meta: array<string,mixed>} */
    public function listCompanyContacts(int|string $cmp, array $params = []): array
    {
        return $this->page($this->request('GET', $this->c($cmp, '/contacts'), $this->query('company_list', $params)));
    }

    /** @return array<string,mixed>|null */
    public function getCompanyContact(int|string $cmp, string $id): ?array
    {
        return $this->dataOrNull('GET', $this->c($cmp, '/contacts/' . rawurlencode($id)));
    }

    /**
     * @return array{contacts: list<array<string,mixed>>, matchCount: int, attributable: bool}
     */
    public function lookupCompany(int|string $cmp, array $params): array
    {
        $r    = $this->request('GET', $this->c($cmp, '/contacts/lookup'), $this->query('company_lookup', $params));
        $list = is_array($r['body']['data'] ?? null) ? array_values($r['body']['data']) : [];
        $n    = (int) ($r['body']['meta']['matchCount'] ?? count($list));

        return ['contacts' => $list, 'matchCount' => $n, 'attributable' => $n === 1];
    }

    /** @return array{data: array<string,mixed>, idempotencyKey: string, replayed: bool} */
    public function createCompanyContact(int|string $cmp, array $contact, ?string $idempotencyKey = null): array
    {
        return $this->create($this->c($cmp, '/contacts'), $contact, $idempotencyKey);
    }

    /** @return array{data: array<string,mixed>, created: bool, idempotencyKey: string, replayed: bool} */
    public function findOrCreateCompanyContact(int|string $cmp, array $body, ?string $idempotencyKey = null): array
    {
        $r = $this->create($this->c($cmp, '/contacts/find-or-create'), $body, $idempotencyKey);

        return $r + ['created' => (bool) ($r['body']['created'] ?? false)];
    }

    /** @return array<string,mixed> */
    public function updateCompanyContact(int|string $cmp, string $id, array $patch, ?int $expectedVersion = null): array
    {
        return $this->patch($this->c($cmp, '/contacts/' . rawurlencode($id)), $patch, $expectedVersion);
    }

    public function archiveCompanyContact(int|string $cmp, string $id): void
    {
        $this->request('PATCH', $this->c($cmp, '/contacts/' . rawurlencode($id) . '/archive'));
    }

    /** @return array<string,mixed>|null */
    public function restoreCompanyContact(int|string $cmp, string $id): ?array
    {
        return $this->data($this->request('POST', $this->c($cmp, '/contacts/' . rawurlencode($id) . '/restore')));
    }

    /**
     * Always a LIST (an identity reference yields at most one contact; others may yield several).
     *
     * @return list<array<string,mixed>>
     */
    public function findByReference(int|string $cmp, string $product, string $refType, string $ref): array
    {
        $r = $this->request('GET', $this->c($cmp, '/contacts/by-reference'), $this->query('by_reference', ['product' => $product, 'ref_type' => $refType, 'ref' => $ref]));

        return is_array($r['body']['data'] ?? null) ? array_values($r['body']['data']) : [];
    }

    /** @return list<array<string,mixed>> */
    public function listReferences(int|string $cmp, string $contactId): array
    {
        return (array) $this->data($this->request('GET', $this->c($cmp, '/contacts/' . rawurlencode($contactId) . '/references')));
    }

    /** @return array{data: array<string,mixed>, created: bool, idempotencyKey: string, replayed: bool} */
    public function addReference(int|string $cmp, string $contactId, string $product, string $refType, string $ref, ?string $caption = null, ?string $idempotencyKey = null): array
    {
        $body = ['product' => $product, 'ref_type' => $refType, 'ref' => $ref] + ($caption !== null ? ['caption' => $caption] : []);
        $r    = $this->create($this->c($cmp, '/contacts/' . rawurlencode($contactId) . '/references'), $body, $idempotencyKey);

        return $r + ['created' => (bool) ($r['body']['created'] ?? false)];
    }

    /** @return array<string,mixed> */
    public function resolveCompany(int|string $cmp, string $id): array
    {
        return (array) $this->data($this->request('GET', $this->c($cmp, '/contacts/' . rawurlencode($id) . '/resolve')));
    }

    /**
     * @param list<string> $ids at most 100
     *
     * @return list<array<string,mixed>>
     */
    public function resolveManyCompany(int|string $cmp, array $ids): array
    {
        self::assertIds($ids);

        return (array) $this->data($this->request('POST', $this->c($cmp, '/contacts/resolve'), [], ['ids' => array_values($ids)]));
    }

    /** @return array{data: list<array<string,mixed>>, nextCursor: ?string} */
    public function companyChanges(int|string $cmp, ?string $since = null, int $limit = 100): array
    {
        return $this->changesFrom($this->c($cmp, '/contacts/changes'), $since, $limit);
    }

    /**
     * Read a stored contact id, following a merge to its survivor. Returns null when the
     * contact is deleted or not readable by the caller. Never use the result to rewrite a
     * historical snapshot (invoice, contract, receipt) — only live references.
     *
     * @return array{contact: array<string,mixed>|null, state: string, survivorId: ?string}
     */
    public function readFollowingMerges(string $id, int|string|null $cmp = null): array
    {
        $res = $cmp === null ? $this->resolve($id) : $this->resolveCompany($cmp, $id);

        return [
            'contact'    => is_array($res['contact'] ?? null) ? $res['contact'] : null,
            'state'      => (string) ($res['state'] ?? 'unknown'),
            'survivorId' => isset($res['survivorId']) ? (string) $res['survivorId'] : null,
        ];
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────

    private function c(int|string $cmp, string $path): string
    {
        $cmp = (string) $cmp;
        if (preg_match('/^[1-9][0-9]{0,17}$/', $cmp) !== 1) {
            throw new \InvalidArgumentException('cmp must be a positive integer company id.');
        }

        return '/companies/' . $cmp . $path;
    }

    /**
     * @return array<string, string>
     */
    private function query(string $endpoint, array $params): array
    {
        $allowed = self::ALLOWED[$endpoint];
        $unknown = array_diff(array_keys($params), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unsupported parameter(s) for ' . $endpoint . ': ' . implode(', ', $unknown) . '. Allowed: ' . implode(', ', $allowed));
        }
        $out = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if ($k === 'ids' && is_array($v)) {
                self::assertIds($v);
                $v = implode(',', $v);
            } elseif (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            } elseif (! is_scalar($v)) {
                throw new \InvalidArgumentException("Parameter {$k} must be a single value.");
            }
            $out[$k] = (string) $v;
        }

        return $out;
    }

    /** @param list<mixed> $ids */
    private static function assertIds(array $ids): void
    {
        if (count($ids) > 100) {
            throw new \InvalidArgumentException('At most 100 ids per call.');
        }
    }

    /** @return array{data: array<string,mixed>, body: array<string,mixed>, idempotencyKey: string, replayed: bool} */
    private function create(string $path, array $body, ?string $key): array
    {
        $key = $key ?? self::uuid4();
        $r   = $this->request('POST', $path, [], $body, ['Idempotency-Key' => $key]);

        return [
            'data'           => is_array($r['body']['data'] ?? null) ? $r['body']['data'] : [],
            'body'           => $r['body'],
            'idempotencyKey' => $key,
            'replayed'       => strtolower($r['headers']['idempotent-replayed'] ?? '') === 'true',
        ];
    }

    /** @return array<string,mixed> */
    private function patch(string $path, array $patch, ?int $expectedVersion): array
    {
        if ($patch === []) {
            throw new \InvalidArgumentException('An update needs at least one field (the server answers 400 empty_update).');
        }
        $headers = $expectedVersion !== null ? ['If-Match' => 'W/"' . $expectedVersion . '"'] : [];

        return (array) $this->data($this->request('PATCH', $path, [], $patch, $headers));
    }

    /** @return array{data: list<array<string,mixed>>, nextCursor: ?string} */
    private function changesFrom(string $path, ?string $since, int $limit): array
    {
        $r = $this->request('GET', $path, $this->query('changes', ['since' => $since, 'limit' => max(1, min(500, $limit))]));

        return [
            'data'       => is_array($r['body']['data'] ?? null) ? array_values($r['body']['data']) : [],
            'nextCursor' => isset($r['body']['meta']['nextCursor']) ? (string) $r['body']['meta']['nextCursor'] : null,
        ];
    }

    /** @return array{contact: array<string,mixed>|null, matchCount: int, attributable: bool} */
    private function lookupResult(array $r): array
    {
        $contact = is_array($r['body']['data'] ?? null) ? $r['body']['data'] : null;
        $n       = isset($r['body']['meta']['matchCount']) ? (int) $r['body']['meta']['matchCount'] : ($contact === null ? 0 : 1);

        return ['contact' => $contact, 'matchCount' => $n, 'attributable' => $n === 1 && $contact !== null];
    }

    /** @return array{data: list<array<string,mixed>>, meta: array<string,mixed>} */
    private function page(array $r): array
    {
        return [
            'data' => is_array($r['body']['data'] ?? null) ? array_values($r['body']['data']) : [],
            'meta' => is_array($r['body']['meta'] ?? null) ? $r['body']['meta'] : [],
        ];
    }

    /** @return array<string,mixed>|list<mixed>|null */
    private function data(array $r): ?array
    {
        return is_array($r['body']['data'] ?? null) ? $r['body']['data'] : null;
    }

    /** @return array<string,mixed>|null */
    private function dataOrNull(string $method, string $path): ?array
    {
        try {
            return $this->data($this->request($method, $path));
        } catch (ContactsApiException $e) {
            if ($e->httpStatus === 404 && $e->errorCode === 'not_found') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string,string>, body: array<string,mixed>}
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null, array $headers = []): array
    {
        $url = $this->baseUrl . $path . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        $lines = ['Accept: application/json', 'User-Agent: ' . ($this->options['userAgent'] ?? 'aicountly-contacts-client/' . self::VERSION)];
        if ($this->delegationToken !== null) {
            $lines[] = 'Authorization: Delegation ' . $this->delegationToken;
        } elseif ($this->sesKey !== null) {
            $lines[] = 'Authorization: Bearer ' . $this->sesKey;
        }
        if ($this->serviceProduct !== null && $this->serviceKey !== null) {
            $lines[] = 'X-AIC-Service: ' . $this->serviceProduct;
            $lines[] = 'X-AIC-Service-Key: ' . $this->serviceKey;
        }
        foreach ($headers as $k => $v) {
            $lines[] = $k . ': ' . $v;
        }
        $payload = null;
        if ($body !== null) {
            $lines[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        [$status, $respHeaders, $raw] = $this->send($method, $url, $lines, $payload);

        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $json    = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            return ['status' => $status, 'headers' => $respHeaders, 'body' => $json];
        }

        throw self::toException($status, $respHeaders, $json);
    }

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $json
     */
    private static function toException(int $status, array $headers, array $json): ContactsApiException
    {
        $err     = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code    = (string) ($err['code'] ?? (is_string($json['error'] ?? null) ? $json['error'] : ''));
        $message = (string) ($err['message'] ?? $json['message'] ?? ('HTTP ' . $status));
        $details = is_array($err['details'] ?? null) ? $err['details'] : [];

        if (in_array($status, [0, 502, 503, 504], true)) {
            $e = new ContactsUnavailableException($status, $code !== '' ? $code : 'service_unavailable', $message !== '' ? $message : 'Contacts is unavailable', $details);
            $ra = $headers['retry-after'] ?? null;
            $e->retryAfterSeconds = $ra !== null && ctype_digit($ra) ? (int) $ra : null;

            return $e;
        }

        return match (true) {
            $status === 401 => new ContactsUnauthorizedException(401, $code !== '' ? $code : 'unauthorized', $message, $details),
            $status === 409 && $code === 'contact_merged' => new ContactMergedException(409, $code, $message, $details),
            $status === 412 => new ContactsPreconditionFailedException(412, $code !== '' ? $code : 'precondition_failed', $message, $details),
            $status === 400 => new ContactsValidationException(400, $code !== '' ? $code : 'validation_failed', $message, $details),
            $status === 404 => new ContactsApiException(404, $code !== '' ? $code : 'not_found', $message, $details),
            default => new ContactsApiException($status, $code !== '' ? $code : 'http_' . $status, $message, $details),
        };
    }

    /**
     * @param list<string> $headerLines
     *
     * @return array{0: int, 1: array<string,string>, 2: string}
     */
    private function send(string $method, string $url, array $headerLines, ?string $payload): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $headerLines, $payload);
        }

        $respHeaders = [];
        $ch          = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => (int) ($this->options['timeout'] ?? 15),
            CURLOPT_CONNECTTIMEOUT => (int) ($this->options['connectTimeout'] ?? 5),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v]                            = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }

                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw    = curl_exec($ch);
        $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, $respHeaders, $raw === false ? '' : (string) $raw];
    }

    private static function uuid4(): string
    {
        $b    = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
