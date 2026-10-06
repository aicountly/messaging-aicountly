<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;
use Aicountly\Api\Env;
use Aicountly\Api\Environment;
use Aicountly\Api\Features;

/**
 * Payment requests and payment status — Aicountly Pay.
 *
 * ## Messaging has no payment gateway and no payment ledger
 *
 * It does not call Razorpay. It does not store a transaction. It does not know
 * what has been collected. It reads a payment request from Pay when somebody
 * asks and shows what Pay says. Anything more would mean two products believing
 * they know what a customer has paid.
 *
 * ## Pay's contract is a payment REQUEST, not a "payment link"
 *
 * This client used to create and read `payment-links` and list `payments`. Pay
 * has never served `payment-links`, and a sibling product's key is refused on
 * `payments` (`service_route_not_allowed`); so every call was a 404 or a 403
 * that the screens read as "Pay could not be reached right now". Nothing in
 * Messaging raised a request through the create call either. They are removed,
 * not re-pointed: raising a payment request is a decision about a customer's
 * money that Messaging does not make yet, and an agent must not be able to say
 * "I've attached a link" on the strength of a call nobody has tested.
 *
 * What remains is the one read Pay does serve a product key: the request, with
 * its payments and its links (`GET v1/payment-requests/{id}`). Pay shows a
 * product only the requests IT raised; anything else answers 404 exactly as a
 * request that does not exist.
 *
 * ## The caller is a person, always
 *
 * Pay admits a sibling product's key on a short route list, and every call must
 * carry ALL of: `X-Service-Key`, `X-AIC-Environment` (the deployment the call is
 * meant for), `X-Actor-Uuid` and that person's own session in `X-Actor-Session`.
 * A key proves which product is calling and nothing about who is acting. With
 * nobody signed in (a journey step, the scheduler) Pay is not called at all,
 * as with Books. Contact access never authorises money movement, and a service
 * key can never request, approve or reject a refund or record money as
 * collected: this client has no call that tries.
 *
 * ## The case this class exists to get right
 *
 * A customer asks for their invoice and a payment link. The assistant drafts a
 * reply. Pay is not connected. The honest outcome is a draft that offers the
 * invoice and says plainly that the payment link is not available — and a SEND
 * BUTTON THAT REFUSES the draft if somebody edits it to claim otherwise.
 *
 * The dishonest outcome, and the one this product must never produce, is a
 * message telling a customer a link is attached when nothing is. Approval
 * cannot override it: an approval is permission to send content, not permission
 * to conjure a resource that does not exist. See
 * Domain/DispatchGuard::assertPromisedResourcesExist().
 */
final class PayClient extends ApiClient
{
    /** A request Pay will still take money against. */
    private const PAYABLE = ['ACTIVE', 'PARTIALLY_PAID'];

    private string $actorUuid = '';
    private string $actorSession = '';

    public function service(): string
    {
        return 'pay';
    }

    protected function productionBase(): string
    {
        return 'https://pay.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://pay.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'PAY_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('PAY');
    }

    public function unavailableMessage(): string
    {
        return 'Aicountly Pay is not connected, so a payment link cannot be created.';
    }

    /**
     * Act for a signed-in person: Pay checks THEIR session against the portal and
     * Manage, and applies their own Pay rights, cut down to reading requests.
     */
    public function forPerson(string $actorUuid, string $sesKey): self
    {
        $clone = clone $this;
        $clone->actorUuid = trim($actorUuid);
        $clone->actorSession = trim($sesKey);

        return $clone;
    }

    /**
     * A payment request Messaging raised, as Pay stands behind it right now:
     * its status, what it asked for, what has been collected and its links.
     *
     * Read live, always. A click on a link is not a payment, and nothing here
     * infers one.
     */
    public function paymentRequest(Context $ctx, string $requestRef): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        $path = 'v1/payment-requests/' . rawurlencode($requestRef);

        if ($this->actorUuid === '' || $this->actorSession === '') {
            // Pay accepts a product key only on behalf of a verified person.
            $this->log('refused', $path, 0, 0.0, 'pay_needs_person');

            return $this->envelope(false, 0, null, 'pay_needs_person', 'unsupported', false,
                'Aicountly Pay is read as the signed-in person; with nobody signed in it is not read.');
        }

        $environment = Environment::current();
        if ($environment === null) {
            $this->log('refused', $path, 0, 0.0, 'environment_not_configured');

            return $this->envelope(false, 0, null, 'environment_not_configured', 'unavailable', false,
                Environment::explainUnconfigured());
        }

        return $this->request(
            'GET',
            $path . self::query($ctx->asQuery()),
            null,
            [
                'X-Service-Key'     => Env::get('PAY_SERVICE_KEY'),
                'X-AIC-Environment' => $environment,
                'X-Actor-Uuid'      => $this->actorUuid,
                'X-Actor-Session'   => $this->actorSession,
            ],
        );
    }

    /**
     * Pay's payment request in the fields Messaging shows and counts.
     *
     * `amount_minor` is what the request ASKED for. `collected_minor` is what
     * Pay says it took, net of refunds, and it is the only figure a collection
     * may be built from: an active link for ₹4,800 has collected nothing.
     * `url` is the link a customer can still pay at, and is empty once the
     * request is paid, expired or cancelled — such a link must not be offered.
     *
     * Pay sends the paid and refunded sums in major units; Messaging holds
     * money in hundredths everywhere else, so they are converted the same way.
     *
     * @param array<string, mixed> $body Pay's `data` object
     * @return array{reference:string, status:string, url:string, amount_minor:int, collected_minor:int, currency:string}
     */
    public static function requestView(array $body, string $fallbackReference, string $fallbackCurrency): array
    {
        $status = strtoupper(trim((string) ($body['status'] ?? '')));

        $url = '';
        if (in_array($status, self::PAYABLE, true)) {
            foreach ((array) ($body['links'] ?? []) as $link) {
                if (is_array($link) && strtoupper((string) ($link['status'] ?? '')) === 'ACTIVE' && trim((string) ($link['url'] ?? '')) !== '') {
                    $url = trim((string) $link['url']);
                    break;
                }
            }
        }

        $reference = trim((string) ($body['reference'] ?? ''));

        return [
            'reference'       => $reference !== '' ? $reference : $fallbackReference,
            'status'          => $status !== '' ? $status : 'UNKNOWN',
            'url'             => $url,
            'amount_minor'    => (int) ($body['amount_minor'] ?? 0),
            'collected_minor' => max(0, (int) round((self::number($body['paid'] ?? 0) - self::number($body['refunded'] ?? 0)) * 100)),
            'currency'        => strtoupper(trim((string) ($body['currency'] ?? ''))) ?: $fallbackCurrency,
        ];
    }

    private static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
