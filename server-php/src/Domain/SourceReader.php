<?php

declare(strict_types=1);

namespace Aicountly\Api\Domain;

use Aicountly\Api\Auth;
use Aicountly\Api\Clients\AppointmentsClient;
use Aicountly\Api\Clients\BooksClient;
use Aicountly\Api\Clients\ContactsClient;
use Aicountly\Api\Clients\PayClient;
use Aicountly\Api\Clients\SalesClient;
use Aicountly\Api\Context;
use Aicountly\Api\Db;

/**
 * Reads an authoritative record from the product that owns it.
 *
 * ## The one place a journey touches another product
 *
 * A journey step names a source (`books_invoice`) and a reference (an invoice
 * id). This class calls the owning product's live API and returns a NORMALISED,
 * MINIMAL view — the handful of fields a condition might branch on, plus the
 * provenance.
 *
 * The normalisation is deliberate and it is narrow. `outstanding_minor`,
 * `status`, `currency`, `label`. Not the invoice document, not the line items,
 * not the customer's address. A journey branches on whether something is paid;
 * it does not need, and must not be handed, the whole record — because whatever
 * it is handed is what ends up in a step-run row, and a step-run row holding an
 * invoice is the mirror this architecture forbids.
 *
 * ## Nothing here is written down
 *
 * The returned array lives for the length of the call. What gets stored is the
 * DECISION a step made and a sentence explaining it: "Books reported the
 * invoice is settled". See migration 003's note on
 * messaging_journey_step_runs.explanation.
 */
final class SourceReader
{
    /**
     * @return array{
     *     state:string, ok:bool, source:string, fetched_at:string,
     *     data:array<string, mixed>, message:string, correlation_id:string
     * }
     */
    public static function read(Context $ctx, Auth $auth, string $source, string $reference): array
    {
        return match ($source) {
            'books_invoice'          => self::booksInvoice($ctx, $auth, $reference),
            'sales_order'            => self::salesOrder($ctx, $auth, $reference),
            'appointments_booking'   => self::appointmentsBooking($ctx, $auth, $reference),
            'pay_payment_link'       => self::payLink($ctx, $reference),
            'contacts_contact'       => self::contact($ctx, $auth, $reference),
            'messaging_conversation' => self::conversation($ctx, $reference),
            default => self::unavailable('unknown', 'Unknown data source "' . $source . '".'),
        };
    }

    private static function booksInvoice(Context $ctx, Auth $auth, string $reference): array
    {
        $client = new BooksClient();
        $client = $auth->sesKey() !== '' ? $client->withSession($auth->sesKey()) : $client->withService($auth->uuid);

        $result = $client->invoice($ctx, $reference);
        if (!$result['ok']) {
            return self::fromEnvelope($result, 'books');
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];

        // Normalised to what a condition branches on. Nothing more.
        $outstanding = self::minor($body, ['outstanding_amount', 'balance', 'outstanding', 'due_amount']);
        $total = self::minor($body, ['total_amount', 'grand_total', 'amount']);

        return self::ready('books', $result['fetched_at'], $result['correlation_id'], [
            'reference'         => (string) ($body['voucher_no'] ?? $body['reference'] ?? $reference),
            'label'             => (string) ($body['voucher_no'] ?? $reference),
            'status'            => strtoupper((string) ($body['status'] ?? ($outstanding > 0 ? 'OUTSTANDING' : 'PAID'))),
            'outstanding_minor' => $outstanding,
            'total_minor'       => $total,
            'currency'          => strtoupper((string) ($body['currency'] ?? Settings::currency($ctx))),
            'due_date'          => (string) ($body['due_date'] ?? ''),
            'contact_uuid'      => (string) ($body['contact_uuid'] ?? ''),
        ]);
    }

    private static function salesOrder(Context $ctx, Auth $auth, string $reference): array
    {
        $client = new SalesClient();
        $client = $auth->sesKey() !== '' ? $client->withSession($auth->sesKey()) : $client->withService($auth->uuid);

        $result = $client->order($ctx, $reference);
        if (!$result['ok']) {
            return self::fromEnvelope($result, 'sales');
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];

        return self::ready('sales', $result['fetched_at'], $result['correlation_id'], [
            'reference'    => (string) ($body['order_no'] ?? $body['reference'] ?? $reference),
            'label'        => (string) ($body['order_no'] ?? $reference),
            'status'       => strtoupper((string) ($body['status'] ?? 'UNKNOWN')),
            'total_minor'  => self::minor($body, ['total_amount', 'grand_total', 'amount']),
            'currency'     => strtoupper((string) ($body['currency'] ?? Settings::currency($ctx))),
            'order_date'   => (string) ($body['order_date'] ?? ''),
            'contact_uuid' => (string) ($body['contact_uuid'] ?? ''),
        ]);
    }

    private static function appointmentsBooking(Context $ctx, Auth $auth, string $reference): array
    {
        $client = new AppointmentsClient();
        $client = $auth->sesKey() !== '' ? $client->withSession($auth->sesKey()) : $client->withService($auth->uuid);

        $result = $client->booking($ctx, $reference);
        if (!$result['ok']) {
            return self::fromEnvelope($result, 'appointments');
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];

        return self::ready('appointments', $result['fetched_at'], $result['correlation_id'], [
            'reference'    => (string) ($body['reference'] ?? $reference),
            'label'        => (string) ($body['reference'] ?? $reference),
            'status'       => strtoupper((string) ($body['status'] ?? 'UNKNOWN')),
            // The agreed time comes from Appointments on every read. Messaging
            // does not keep it — see Clients/AppointmentsClient.
            'starts_at'    => (string) ($body['starts_at'] ?? ''),
            'timezone'     => (string) ($body['timezone'] ?? ''),
            'service'      => (string) ($body['service_name'] ?? ''),
            'contact_uuid' => (string) ($body['contact_uuid'] ?? ''),
        ]);
    }

    private static function payLink(Context $ctx, string $reference): array
    {
        $result = (new PayClient())->paymentLink($ctx, $reference);
        if (!$result['ok']) {
            return self::fromEnvelope($result, 'pay');
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];

        return self::ready('pay', $result['fetched_at'], $result['correlation_id'], [
            'reference' => (string) ($body['reference'] ?? $reference),
            'label'     => (string) ($body['reference'] ?? $reference),
            'status'    => strtoupper((string) ($body['status'] ?? 'UNKNOWN')),
            'url'       => (string) ($body['url'] ?? ''),
            'amount_minor' => self::minor($body, ['amount_minor', 'amount']),
            'currency'  => strtoupper((string) ($body['currency'] ?? Settings::currency($ctx))),
        ]);
    }

    private static function contact(Context $ctx, Auth $auth, string $reference): array
    {
        $client = new ContactsClient();
        if ($auth->sesKey() !== '') {
            $client = $client->withSession($auth->sesKey());
        }

        $result = $client->contact($reference);
        if (!$result['ok']) {
            return self::fromEnvelope($result, 'contacts');
        }

        $body = $result['body']['data'] ?? $result['body'] ?? [];

        return self::ready('contacts', $result['fetched_at'], $result['correlation_id'], [
            'contact_uuid' => (string) ($body['contact_uuid'] ?? $reference),
            'label'        => (string) ($body['name'] ?? ''),
            'name'         => (string) ($body['name'] ?? ''),
            'mobile'       => (string) ($body['mobile'] ?? ''),
            'email'        => (string) ($body['email'] ?? ''),
            'language'     => (string) ($body['preferred_language'] ?? ''),
        ]);
    }

    /** Messaging's own data. No cross-product call, so it cannot be unavailable. */
    private static function conversation(Context $ctx, string $reference): array
    {
        $row = Db::first(
            'SELECT conversation_uuid, status, customer_address, contact_uuid, channel, language,
                    last_inbound_at, last_outbound_at
             FROM messaging_conversations WHERE cmp_id = :cmp AND conversation_uuid = :uuid',
            ['cmp' => $ctx->cmpId, 'uuid' => $reference],
        );

        if ($row === null) {
            return self::unavailable('messaging', 'That conversation could not be found.');
        }

        $awaiting = $row['last_inbound_at'] !== null
            && ($row['last_outbound_at'] === null || $row['last_outbound_at'] < $row['last_inbound_at']);

        return self::ready('messaging', gmdate('c'), '', [
            'conversation_uuid' => (string) $row['conversation_uuid'],
            'label'             => (string) $row['customer_address'],
            'status'            => strtoupper((string) $row['status']),
            'awaiting_reply'    => $awaiting,
            'contact_uuid'      => (string) ($row['contact_uuid'] ?? ''),
            'channel'           => (string) $row['channel'],
            'language'          => (string) ($row['language'] ?? ''),
        ]);
    }

    /**
     * Evaluate a journey condition against a source reading.
     *
     * A TINY, CLOSED expression language. Deliberately not `eval`, not a
     * general expression parser, and not anything a natural-language builder
     * could talk into running arbitrary code: the left side must be a known
     * field, the operator must be one of six, and the right side is a literal.
     *
     * @param array<string, mixed> $data
     * @return array{result:bool, explanation:string}
     */
    public static function evaluateCondition(string $expression, array $data): array
    {
        $expression = trim($expression);
        if ($expression === '') {
            return ['result' => true, 'explanation' => 'No condition was set, so the step continued.'];
        }

        if (preg_match('/^source\.([a-z_]+)\s*(==|!=|>|<|>=|<=|not in|in)\s*(.+)$/i', $expression, $m) !== 1) {
            return ['result' => false, 'explanation' => 'The condition could not be read, so the step did not continue.'];
        }

        $field = strtolower($m[1]);
        $operator = strtolower(trim($m[2]));
        $literal = trim($m[3], " \t\"'");

        if (!array_key_exists($field, $data)) {
            return [
                'result'      => false,
                'explanation' => 'The source did not report "' . $field . '", so the condition could not be evaluated.',
            ];
        }

        $actual = $data[$field];

        if ($operator === 'in' || $operator === 'not in') {
            $values = array_map(
                static fn (string $v) => strtoupper(trim($v, " \t\"'")),
                explode(',', $literal),
            );
            $isIn = in_array(strtoupper((string) $actual), $values, true);
            $result = $operator === 'in' ? $isIn : !$isIn;

            return [
                'result'      => $result,
                'explanation' => 'The source reported ' . $field . ' as "' . (string) $actual . '", which is '
                    . ($isIn ? '' : 'not ') . 'one of ' . implode(', ', $values) . '.',
            ];
        }

        $numericComparison = is_numeric($literal) && (is_numeric($actual) || is_bool($actual));
        $left = $numericComparison ? (float) $actual : self::scalarise($actual);
        $right = $numericComparison ? (float) $literal : self::literal($literal);

        $result = match ($operator) {
            '=='  => $left == $right,
            '!='  => $left != $right,
            '>'   => $left > $right,
            '<'   => $left < $right,
            '>='  => $left >= $right,
            '<='  => $left <= $right,
            default => false,
        };

        return [
            'result'      => $result,
            'explanation' => 'The source reported ' . $field . ' as "' . self::display($actual) . '"; the condition '
                . 'required ' . $operator . ' ' . $literal . '.',
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * Money as minor units, whatever shape the owning product reports it in.
     *
     * Products in this fleet are not uniform: some report `18500.00` and some
     * report minor units already. A key ending `_minor` is taken as minor; a
     * plain amount is multiplied. Getting this wrong by a factor of a hundred
     * in a payment reminder is not a rounding error, so the rule is explicit
     * rather than inferred from the magnitude.
     *
     * @param array<string, mixed> $body
     * @param list<string>         $keys
     */
    private static function minor(array $body, array $keys): int
    {
        foreach ($keys as $key) {
            if (isset($body[$key . '_minor']) && is_numeric($body[$key . '_minor'])) {
                return (int) $body[$key . '_minor'];
            }
        }
        foreach ($keys as $key) {
            if (isset($body[$key]) && is_numeric($body[$key])) {
                return str_ends_with($key, '_minor')
                    ? (int) $body[$key]
                    : (int) round((float) $body[$key] * 100);
            }
        }

        return 0;
    }

    private static function scalarise(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private static function literal(string $raw): string|bool
    {
        $lower = strtolower($raw);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }

        return $raw;
    }

    private static function display(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '(not a simple value)';
    }

    /** @param array<string, mixed> $data */
    private static function ready(string $source, string $fetchedAt, string $correlationId, array $data): array
    {
        return [
            'state'          => 'ready',
            'ok'             => true,
            'source'         => $source,
            'fetched_at'     => $fetchedAt,
            'data'           => $data,
            'message'        => '',
            'correlation_id' => $correlationId,
        ];
    }

    /** @param array<string, mixed> $envelope */
    private static function fromEnvelope(array $envelope, string $source): array
    {
        return [
            'state'          => (string) ($envelope['state'] ?? 'unavailable'),
            'ok'             => false,
            'source'         => $source,
            'fetched_at'     => (string) ($envelope['fetched_at'] ?? gmdate('c')),
            'data'           => [],
            'message'        => (string) ($envelope['message'] ?? 'The source could not be read.'),
            'correlation_id' => (string) ($envelope['correlation_id'] ?? ''),
        ];
    }

    private static function unavailable(string $source, string $message): array
    {
        return [
            'state'          => 'unavailable',
            'ok'             => false,
            'source'         => $source,
            'fetched_at'     => gmdate('c'),
            'data'           => [],
            'message'        => $message,
            'correlation_id' => '',
        ];
    }
}
