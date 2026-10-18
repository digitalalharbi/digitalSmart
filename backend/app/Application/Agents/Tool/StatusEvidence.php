<?php

declare(strict_types=1);

namespace App\Application\Agents\Tool;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Agents\Query\ResolveOperatingRules;
use App\Application\Operations\DTO\ProposalOrigin;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;

/**
 * The evidence for a pause or resume, built from the product's own figures — never from what the caller said.
 *
 * It names the target compared against and where that target came from. Where no target exists the evidence says so
 * rather than leaving a decider to assume one.
 */
final readonly class StatusEvidence
{
    public function __construct(
        private GetCampaignControl $campaigns,
        private ResolveOperatingRules $rules,
        private ActorContext $actor,
        private Clock $clock,
    ) {}

    public function for(string $campaignId, string $agent, string $risk): ProposalOrigin
    {
        $row = ($this->campaigns)(new CampaignControlFilter(campaignIds: [$campaignId]), 1)->rows[0]
            ?? throw ResourceNotFound::of('Campaign');

        $rules = ($this->rules)($this->actor->workspaceId()->value, $row->clientId, $row->projectId);

        return new ProposalOrigin(
            agent: $agent,
            evidence: [
                'health' => $row->health->value,
                'health_reason' => $row->healthReason,
                'currency' => $row->currency,
                'spend_minor' => $row->spendMinor,
                'results' => $row->results,
                'cost_per_result_minor' => $row->costPerResultMinor,
                'roas' => $row->roas,
                'target_cpa_minor' => $rules->targetCpaMinor,
                'target_cpa_source' => $rules->sourceOf('target_cpa_minor'),
                'target_roas' => $rules->targetRoas,
                'target_roas_source' => $rules->sourceOf('target_roas'),
                'revenue_authority' => $rules->revenueAuthority->value,
            ],
            risk: $risk,
            expiresAt: $this->clock->now()->modify('+3 days'),
        );
    }
}
