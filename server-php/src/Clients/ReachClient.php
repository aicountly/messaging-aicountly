<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;
use Aicountly\Api\Env;
use Aicountly\Api\Features;

/**
 * Campaign planning — Aicountly Reach.
 *
 * ## The division of labour, which is easy to get wrong in both directions
 *
 * Reach plans campaigns: audiences, scheduling, the marketing calendar, the
 * multi-channel plan. Messaging executes channel-specific message sends,
 * owns its templates, and runs OPERATIONAL journeys — an overdue invoice
 * reminder is not a campaign.
 *
 * So Messaging does NOT build: audience segmentation, campaign budgets, a
 * marketing calendar, A/B test orchestration across channels. When somebody on
 * the Business Outcomes screen wants to turn an observation into a campaign, the
 * link goes to Reach.
 *
 * And Messaging does NOT defer: a payment reminder, an appointment reminder or
 * an order update is operational, it is triggered by a business event rather
 * than a marketing plan, and it belongs here.
 */
final class ReachClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'reach';
    }

    protected function productionBase(): string
    {
        return 'https://reach.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://reach.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'REACH_API_BASE';
    }

    public function configured(): bool
    {
        return Features::enabled('REACH');
    }

    public function unavailableMessage(): string
    {
        return 'Aicountly Reach is not connected, so campaign planning is not linked from here.';
    }

    public function withSession(string $sesKey): self
    {
        $clone = clone $this;
        $clone->authorization = 'Bearer ' . trim($sesKey);

        return $clone;
    }

    /**
     * Campaigns that reference Messaging sends, so Business Outcomes can say
     * "this reminder was part of a Reach campaign" without owning campaigns.
     *
     * @param array<string, mixed> $filters
     */
    public function campaigns(Context $ctx, array $filters = []): array
    {
        if (!$this->configured()) {
            return $this->pendingResult();
        }

        return $this->request(
            'GET',
            'v1/campaigns' . self::query(['limit' => 25] + $filters + $ctx->asQuery()),
            null,
            $this->authorization !== ''
                ? ['Authorization' => $this->authorization]
                : ['X-Service-Key' => Env::get('REACH_SERVICE_KEY')],
        );
    }
}
