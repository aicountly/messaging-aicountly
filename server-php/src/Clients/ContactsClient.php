<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Features;

/**
 * Who the customer is — Aicountly Contacts.
 *
 * THERE IS NO CONTACT TABLE IN THIS PRODUCT AND THERE WILL NOT BE ONE. A
 * conversation holds a `contact_uuid` and the transport address the message
 * arrived from, and that is all. Names, alternate numbers, email addresses and
 * addresses are read from Contacts on the request that shows them.
 *
 * The temptation here is specific and worth naming: an inbox list showing
 * "Priya Sharma" for two hundred rows is two hundred lookups, and the obvious
 * fix is a `customer_name` column filled in when the conversation was created.
 * That column is a second contact master. It goes stale the day somebody
 * corrects a spelling, and then the inbox and Contacts disagree about a
 * customer's name with no way to say which is right. The actual fix is a
 * batched live read (see resolveMany) whose results live for the length of one
 * request.
 *
 * `provider_profile_name` on a conversation is not an exception to this. It is
 * what WhatsApp said the sender calls themselves, it is labelled as such in the
 * UI, and it is never presented as the customer's name from Contacts.
 */
final class ContactsClient extends ApiClient
{
    private string $authorization = '';

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
        $clone->authorization = 'Bearer ' . trim($sesKey);

        return $clone;
    }

    /** @param array<string, mixed> $filters */
    public function search(string $term, array $filters = []): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'contacts' . self::query(['q' => $term, 'limit' => 25] + $filters),
            null,
            ['Authorization' => $this->authorization],
        );
    }

    public function contact(string $contactUuid): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'contacts/' . rawurlencode($contactUuid),
            null,
            ['Authorization' => $this->authorization],
        );
    }

    /**
     * Find the contact behind a phone number.
     *
     * This is how an inbound WhatsApp message from an unknown number becomes a
     * named customer with an order history: Contacts is asked, live. A match is
     * stored as a `contact_uuid` reference on the conversation and nothing else.
     */
    public function findByPhone(string $e164): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'contacts' . self::query(['mobile' => $e164, 'limit' => 5]),
            null,
            ['Authorization' => $this->authorization],
        );
    }

    /**
     * Resolve several contacts in one call, for a list screen.
     *
     * The alternative — one request per row — is what makes people add a name
     * column to their own table. Where the API supports a batch read this uses
     * it; the results are held for the length of the request by ApiClient's memo
     * and are never written down.
     *
     * @param list<string> $contactUuids
     */
    public function resolveMany(array $contactUuids): array
    {
        $ids = array_values(array_unique(array_filter($contactUuids)));
        if ($ids === []) {
            return $this->envelopeEmpty();
        }
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'contacts' . self::query(['uuids' => implode(',', array_slice($ids, 0, 100)), 'limit' => 100]),
            null,
            ['Authorization' => $this->authorization],
        );
    }

    /** A successful no-op, so callers need not special-case "nothing to ask about". */
    private function envelopeEmpty(): array
    {
        return [
            'ok' => true, 'status' => 200, 'body' => ['data' => []], 'error' => null,
            'state' => 'ready', 'source' => 'contacts', 'fetched_at' => gmdate('c'),
            'retryable' => false, 'message' => '', 'correlation_id' => $this->correlationId(),
        ];
    }
}
