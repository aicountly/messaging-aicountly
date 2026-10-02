<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\CrossServiceCallContext;
use Aicountly\Api\Features;
use Aicountly\Api\Support\PhoneNumber;
use AicountlyContacts\ContactMergedException;
use AicountlyContacts\ContactsApiClient;
use AicountlyContacts\ContactsApiException;
use AicountlyContacts\ContactsUnauthorizedException;
use AicountlyContacts\ContactsUnavailableException;
use AicountlyContacts\ContactsValidationException;

require_once __DIR__ . '/vendor-contacts-client/ContactsApiClient.php';

/**
 * Who the customer is — Aicountly Contacts, contract v1, COMPANY directory.
 *
 * THERE IS NO CONTACT TABLE IN THIS PRODUCT AND THERE WILL NOT BE ONE. A
 * conversation holds a `contact_uuid` (a Contacts company-contact id) and the
 * transport address the message arrived from, and that is all. Names, numbers
 * and e-mail addresses are read from Contacts on the request that shows them,
 * through the shared client (vendor-contacts-client, drift-checked by
 * scripts/ci/contacts-client-drift-check.sh), with the person's own session.
 *
 * ## What changed (G19#1, G19#2, G19#3, G19#12, G19#13)
 *
 *   COMPANY ENDPOINTS  every read is /companies/{cmp}/contacts…: the inbox
 *                      belongs to a company, so its customers are the
 *                      company's contacts — never one employee's personal book.
 *   LOOKUP, NOT SEARCH a number is looked up (`/contacts/lookup?phone=`), and a
 *                      conversation is attributed ONLY when Contacts reports
 *                      meta.matchCount === 1 and that contact holds the number.
 *                      Two matches is "ambiguous", never "the first one"; none
 *                      is "unknown", never a fallback.
 *   CANONICAL FIELDS   displayName, phones[{value}], emails[{value}] — the old
 *                      reads of `name`/`mobile`/`email` were fields Contacts
 *                      never sent, so every name rendered blank.
 *   HONEST FAILURES    session refused, no access, not found/merged away and
 *                      unavailable are told apart (error code + message), so
 *                      the inbox never says "no such customer" when Contacts
 *                      was merely down.
 *
 * Messaging holds no Contacts key: there is nothing it may read there that the
 * signed-in person may not.
 *
 * Every method answers in ApiClient's envelope (ok/state/source/fetched_at/
 * message/…), with `body` = {data, meta} where data is the VIEW below.
 */
final class ContactsClient extends ApiClient
{
    private string $sesKey = '';

    public function service(): string
    {
        return 'contacts';
    }

    protected function productionBase(): string
    {
        return 'https://contacts.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://contacts.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CONTACTS_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('CONTACTS');
    }

    public function unavailableMessage(): string
    {
        return 'Aicountly Contacts is not connected, so customer names and details cannot be shown.';
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->sesKey = trim($sesKey);

        return $clone;
    }

    /** The company directory, searched. */
    public function search(int $cmpId, string $term, int $limit = 25, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $page = intdiv(max(0, $offset), $limit) + 1;

        return $this->run(function (ContactsApiClient $c) use ($cmpId, $term, $limit, $page): array {
            $found = $c->listCompanyContacts($cmpId, ['q' => trim($term), 'page' => $page, 'per_page' => $limit]);
            $views = array_map(static fn (array $row): array => self::view($row), array_values(array_filter($found['data'], 'is_array')));

            return [$views, ['total' => (int) ($found['meta']['total'] ?? count($views))]];
        });
    }

    /**
     * One company contact by its id, following a merge to the survivor. A
     * contact that is gone (deleted, or not in this company) is not-found —
     * state `unsupported`, error `contact_gone` — never an outage.
     */
    public function contact(int $cmpId, string $contactId): array
    {
        return $this->run(function (ContactsApiClient $c) use ($cmpId, $contactId): array {
            $read = $c->readFollowingMerges($contactId, $cmpId);
            if ($read['contact'] === null) {
                throw new ContactsApiException(404, 'contact_gone', 'state ' . $read['state']);
            }
            $view = self::view($read['contact']);
            $view['requested_id'] = $contactId;

            return [$view, ['state' => $read['state'], 'survivor_id' => $read['survivorId']]];
        });
    }

    /**
     * Who holds this number in the company's directory. `meta.attributable` is
     * true only for exactly one match that holds the number (E.164 compared in
     * the number's own country). data = every match, for an agent to pick from.
     */
    public function lookupPhone(int $cmpId, string $e164): array
    {
        $e164 = PhoneNumber::toE164($e164) ?? '';
        if ($e164 === '') {
            return $this->envelope(true, 200, ['data' => [], 'meta' => ['matchCount' => 0, 'attributable' => false]], null, 'ready', false);
        }
        $region = PhoneNumber::regionOf($e164);

        return $this->run(function (ContactsApiClient $c) use ($cmpId, $e164, $region): array {
            $found = $c->lookupCompany($cmpId, ['phone' => $e164]);
            $views = array_map(static fn (array $row): array => self::view($row, $region), array_values(array_filter($found['contacts'], 'is_array')));
            $holds = $found['matchCount'] === 1 && count($views) === 1
                && in_array($e164, array_column($views[0]['phones'], 'e164'), true);

            return [$views, ['matchCount' => $found['matchCount'], 'attributable' => $holds]];
        });
    }

    /**
     * Resolve stored ids in one call, for a list screen. data = views of the
     * readable ones, each carrying `requested_id` (the stored id) and `id`
     * (the survivor when it was merged).
     *
     * @param list<string> $contactIds
     */
    public function resolveMany(int $cmpId, array $contactIds): array
    {
        $ids = array_values(array_unique(array_filter($contactIds, static fn ($id) => is_string($id) && $id !== '')));
        if ($ids === []) {
            return $this->envelope(true, 200, ['data' => [], 'meta' => []], null, 'ready', false);
        }

        return $this->run(function (ContactsApiClient $c) use ($cmpId, $ids): array {
            $out = [];
            foreach (array_chunk($ids, 100) as $chunk) {
                foreach ($c->resolveManyCompany($cmpId, $chunk) as $row) {
                    if (!is_array($row) || !is_array($row['contact'] ?? null)) {
                        continue;
                    }
                    $out[] = self::view($row['contact']) + ['requested_id' => (string) ($row['id'] ?? '')];
                }
            }

            return [$out, []];
        });
    }

    /**
     * The Books ledger account a company contact is EXPLICITLY linked to in
     * Contacts (reference product=books, ref_type=ledger_account), or null.
     * Two different accounts is not a link, it is a question: null, with
     * meta.ambiguous (G19#5).
     */
    public function ledgerAccount(int $cmpId, string $contactId): array
    {
        return $this->run(function (ContactsApiClient $c) use ($cmpId, $contactId): array {
            $accounts = [];
            foreach ($c->listReferences($cmpId, $contactId) as $ref) {
                if (is_array($ref) && strtolower((string) ($ref['product'] ?? '')) === 'books'
                    && strtolower((string) ($ref['refType'] ?? '')) === 'ledger_account'
                    && trim((string) ($ref['ref'] ?? '')) !== '') {
                    $accounts[trim((string) $ref['ref'])] = true;
                }
            }
            $accounts = array_keys($accounts);

            return [count($accounts) === 1 ? ['acc_id' => (string) $accounts[0]] : null, ['ambiguous' => count($accounts) > 1]];
        });
    }

    /**
     * A Contacts contact (contract v1) in the shape Messaging shows.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function view(array $row, ?string $region = null): array
    {
        $phones = [];
        foreach (is_array($row['phones'] ?? null) ? $row['phones'] : [] as $phone) {
            $value = is_array($phone) ? trim((string) ($phone['value'] ?? '')) : '';
            if ($value === '') {
                continue;
            }
            $phones[] = [
                'value' => $value,
                'e164'  => PhoneNumber::toE164($value, $region ?? 'IN'),
                'label' => is_array($phone) && isset($phone['label']) ? (string) $phone['label'] : null,
            ];
        }
        $emails = [];
        foreach (is_array($row['emails'] ?? null) ? $row['emails'] : [] as $email) {
            $value = is_array($email) ? trim((string) ($email['value'] ?? '')) : '';
            if ($value !== '') {
                $emails[] = ['value' => $value, 'label' => is_array($email) && isset($email['label']) ? (string) $email['label'] : null];
            }
        }
        $id = (string) ($row['id'] ?? '');

        return [
            'contact_uuid' => $id,
            'id'           => $id,
            'name'         => (string) ($row['displayName'] ?? ''),
            'organization' => (string) ($row['organizationName'] ?? ''),
            // The first of each is the owner's primary (Contacts keeps their order).
            'mobile'       => (string) ($phones[0]['e164'] ?? $phones[0]['value'] ?? ''),
            'email'        => (string) ($emails[0]['value'] ?? ''),
            'phones'       => $phones,
            'emails'       => $emails,
            'state'        => (string) ($row['state'] ?? 'active'),
            'scope'        => isset($row['cmpId']) ? 'company' : 'personal',
        ];
    }

    /**
     * One call through the shared client, answered in ApiClient's envelope.
     *
     * @param callable(ContactsApiClient): array{0: mixed, 1: array<string, mixed>} $call
     */
    private function run(callable $call): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }
        if ($this->base() === '') {
            return $this->envelope(false, 0, null, 'environment_not_configured', 'unavailable', false,
                \Aicountly\Api\Environment::explainUnconfigured());
        }
        if (CrossServiceCallContext::isInboundFrom('contacts')) {
            return $this->envelope(false, 0, null, 'contacts_reentrant_call_refused', 'unavailable', false,
                'Contacts is not shown here, because Contacts is waiting on this request.');
        }
        if ($this->sesKey === '') {
            // A product key acting with nobody signed in reads nothing here.
            return $this->envelope(false, 0, null, 'contacts_needs_person', 'forbidden', false,
                'Customer details come from Aicountly Contacts as the signed-in person; nobody is signed in.');
        }

        $client = (new ContactsApiClient($this->apiRoot(), ['transport' => $this->transport(...)]))->withSession($this->sesKey);

        try {
            [$data, $meta] = $call($client);

            return $this->envelope(true, 200, ['data' => $data, 'meta' => $meta], null, 'ready', false);
        } catch (ContactsUnavailableException $e) {
            return $this->envelope(false, $e->httpStatus, null, 'contacts_unavailable', 'unavailable', true,
                'Aicountly Contacts could not answer right now. Nothing has been guessed.');
        } catch (ContactsUnauthorizedException $e) {
            return $this->envelope(false, 401, null, 'contacts_session_refused', 'unavailable', false,
                'Aicountly Contacts did not accept your session. Sign in again if this continues.');
        } catch (ContactsValidationException $e) {
            return $this->envelope(false, 400, null, 'contacts_request_refused', 'unavailable', false,
                'Aicountly Contacts refused the request: ' . $e->getMessage());
        } catch (ContactMergedException $e) {
            return $this->envelope(false, 409, null, 'contact_merged', 'unsupported', false,
                'That contact was merged into another in Aicountly Contacts.');
        } catch (ContactsApiException $e) {
            return match (true) {
                $e->httpStatus === 403 => $this->envelope(false, 403, null, 'contacts_forbidden', 'forbidden', false,
                    'You do not have access to this company\'s contacts in Aicountly Contacts.'),
                $e->httpStatus === 404 => $this->envelope(false, 404, null, 'contact_gone', 'unsupported', false,
                    'That contact is not in this company\'s Aicountly Contacts (deleted, or never shared with the company).'),
                $e->httpStatus === 429 => $this->envelope(false, 429, null, 'contacts_rate_limited', 'unavailable', true,
                    'Aicountly Contacts asked us to slow down. Try again shortly.'),
                $e->httpStatus >= 500  => $this->envelope(false, $e->httpStatus, null, 'contacts_unavailable', 'unavailable', true,
                    'Aicountly Contacts could not answer right now. Nothing has been guessed.'),
                default                => $this->envelope(false, $e->httpStatus, null, 'contacts_error', 'unavailable', false,
                    'Aicountly Contacts answered ' . $e->httpStatus . ': ' . $e->getMessage()),
            };
        } catch (\InvalidArgumentException|\JsonException $e) {
            return $this->envelope(false, 400, null, 'contacts_request_invalid', 'unavailable', false, $e->getMessage());
        }
    }

    /**
     * The shared client's transport, with this product's headers and logging.
     *
     * @param list<string> $headerLines
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function transport(string $method, string $url, array $headerLines, ?string $payload): array
    {
        $headerLines[] = CrossServiceCallContext::HEADER . ': ' . $this->selfName();
        $headerLines[] = 'X-Source-App: ' . $this->selfName();
        $headerLines[] = 'X-Correlation-Id: ' . $this->correlationId();
        $headerLines[] = 'Cache-Control: no-store';

        $respHeaders = [];
        $startedAt = microtime(true);
        $ch = curl_init($url);
        if ($ch === false) {
            return [0, [], ''];
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_OPTIONAL,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT_OPTIONAL,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }

                return strlen($line);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
        $raw = curl_exec($ch);
        $status = $raw === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status === 0 || $status >= 400) {
            // Path only: the query carries phone numbers and ids.
            error_log(sprintf(
                '[cross-service] service=contacts outcome=%s operation=%s status=%d ms=%d cid=%s',
                $status === 0 ? 'failed' : 'error',
                (string) (parse_url($url, PHP_URL_PATH) ?? ''),
                $status,
                (int) ((microtime(true) - $startedAt) * 1000),
                $this->correlationId(),
            ));
        }

        return [$status, $respHeaders, $raw === false ? '' : (string) $raw];
    }
}
