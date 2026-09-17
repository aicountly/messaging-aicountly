<?php

declare(strict_types=1);

namespace Aicountly\Api\Channels;

/**
 * What came back from one attempt to hand a message to a provider.
 *
 * THE THIRD OUTCOME IS THE POINT OF THIS CLASS. A send does not either work or
 * fail — it can also time out after the provider may already have accepted it,
 * and that is a genuinely different thing:
 *
 *   accepted  the provider has it, and gave us its id
 *   failed    the provider refused it, and said why. Safe to not send.
 *   unknown   we do not know. Retrying might deliver it twice; giving up might
 *             mean the customer got it and we told the agent it failed.
 *
 * A codebase with only a boolean will treat `unknown` as `failed`, retry, and
 * send the customer two payment reminders. So `unknown` is a first-class state
 * here, it maps to the `submission_unknown` message status, and it is resolved
 * by asking the provider (Capability::STATUS_LOOKUP) or by a human — never by
 * guessing.
 */
final class SendResult
{
    private function __construct(
        public readonly string $outcome,            // accepted | failed | unknown
        public readonly ?string $providerMessageId,
        public readonly ?string $errorCode,
        public readonly ?string $errorDetail,
        public readonly bool $retryable,
        public readonly ?int $costMinor,
        public readonly ?string $costCurrency,
    ) {
    }

    public static function accepted(string $providerMessageId, ?int $costMinor = null, ?string $currency = null): self
    {
        return new self('accepted', $providerMessageId, null, null, false, $costMinor, $currency);
    }

    /**
     * The provider said no.
     *
     * `retryable` is the provider's answer, not a guess: a rate limit is worth
     * retrying and an invalid number is not, and retrying the second one
     * forever is how a queue fills up with messages that will never send.
     */
    public static function failed(string $code, string $detail, bool $retryable = false): self
    {
        return new self('failed', null, $code, $detail, $retryable, null, null);
    }

    /**
     * We do not know whether the provider took it.
     *
     * NOT retryable by default, and deliberately so. The caller must reconcile
     * rather than resend.
     */
    public static function unknown(string $detail): self
    {
        return new self('unknown', null, 'submission_unknown', $detail, false, null, null);
    }

    public function isAccepted(): bool
    {
        return $this->outcome === 'accepted';
    }

    public function isUnknown(): bool
    {
        return $this->outcome === 'unknown';
    }
}
