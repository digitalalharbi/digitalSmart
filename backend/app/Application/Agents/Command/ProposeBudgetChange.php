<?php

declare(strict_types=1);

namespace App\Application\Agents\Command;

use App\Application\Advertising\Contract\CampaignControlReadModel;
use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignVitalsRow;
use App\Application\Agents\Contract\ProposalHistory;
use App\Application\Agents\Query\ResolveOperatingRules;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Command\RequestApproval;
use App\Application\Operations\DTO\ApprovalInput;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Agents\ActionRefused;
use App\Domain\Integration\Provider;
use App\Domain\Integration\WriteOperation;
use App\Domain\Integration\WriteSupport;
use App\Domain\Operations\TaskSubjectType;

/**
 * Propose a new daily budget for one campaign.
 *
 * **This changes nothing.** It writes an approval request, built here from the product's own records, and it is
 * refused — in code, whoever is asking — when it would break a limit:
 *
 *  - only a campaign with its own **daily** budget, on a provider whose budget write has been read in its own
 *    documentation;
 *  - never the budget it already has;
 *  - never a move bigger than the project's `max_budget_change_pct` (the agency default where nobody set one);
 *  - never above the project's `max_daily_budget`;
 *  - never a second request while one is waiting, and never another within a day of the last.
 *
 * Deciding and carrying out remain two more acts, by two more permissions.
 */
final readonly class ProposeBudgetChange
{
    public function __construct(
        private CampaignControlReadModel $campaigns,
        private ResolveOperatingRules $rules,
        private ProposalHistory $history,
        private RequestApproval $ask,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(string $campaignId, int $newDailyMinor, string $reason, string $agent): ApprovalView
    {
        $workspaceId = $this->actor->workspaceId()->value;
        $now = $this->clock->now();

        $rows = $this->campaigns->vitals(
            $workspaceId,
            $this->visibility->visibleProjectIds(),
            new CampaignControlFilter(campaignIds: [$campaignId]),
            $now->modify('-28 days'),
            $now->modify('-1 day'),
            1,
        );
        /** @var CampaignVitalsRow $row */
        $row = $rows[0] ?? throw ResourceNotFound::of('Campaign');

        $target = $this->campaigns->writeTarget($workspaceId, $this->visibility->visibleProjectIds(), $campaignId)
            ?? throw ResourceNotFound::of('Campaign');

        $provider = Provider::tryFrom($target['provider']);

        if ($provider === null || $provider->writeSupport(WriteOperation::SetCampaignDailyBudget) !== WriteSupport::Documented) {
            throw ActionRefused::notDocumented($provider === null ? $target['provider'] : $provider->label());
        }

        if ($row->budgetMode !== 'daily' || $row->budgetMinor === null || $row->budgetMinor <= 0) {
            throw ActionRefused::notDaily();
        }

        $current = $row->budgetMinor;

        if ($newDailyMinor === $current) {
            throw ActionRefused::unchanged();
        }

        $rules = ($this->rules)($workspaceId, $target['client_id'], $target['project_id']);
        $changePct = (int) round(abs($newDailyMinor - $current) / $current * 100);

        if ($changePct > $rules->maxBudgetChangePct) {
            throw ActionRefused::tooLarge($rules->maxBudgetChangePct);
        }

        if ($rules->maxDailyBudgetMinor !== null && $newDailyMinor > $rules->maxDailyBudgetMinor) {
            throw ActionRefused::aboveCeiling();
        }

        $kind = WriteOperation::SetCampaignDailyBudget->authorisedBy();

        if ($this->history->hasPending($workspaceId, $kind, $target['external_campaign_id'])) {
            throw ActionRefused::alreadyProposed();
        }

        if ($this->history->hasSince($workspaceId, $kind, $target['external_campaign_id'], $now->modify('-1 day'))) {
            throw ActionRefused::coolingDown();
        }

        $currency = $target['currency'] ?? '';

        return ($this->ask)(new ApprovalInput(
            kind: $kind,
            title: sprintf('%s daily budget — %s', $newDailyMinor > $current ? 'Raise' : 'Lower', $target['name']),
            rationale: $reason,
            proposal: [
                'provider' => $target['provider'],
                'external_account_id' => $target['external_account_id'],
                'external_campaign_id' => $target['external_campaign_id'],
                'campaign' => $target['name'],
                'change' => sprintf('%s the daily budget by %d%%', $newDailyMinor > $current ? 'Raise' : 'Lower', $changePct),
                /* Money for a person to read, in major units; the carry-out step reads `daily_budget_minor`. */
                'current' => $current / 100,
                'requested' => $newDailyMinor / 100,
                'currency' => $currency,
                'daily_budget_minor' => $newDailyMinor,
            ],
            clientId: $target['client_id'],
            projectId: $target['project_id'],
            subjectType: TaskSubjectType::Campaign,
            subjectId: $campaignId,
            origin: 'agent',
            agent: $agent,
            evidence: [
                'current_daily_minor' => $current,
                'proposed_daily_minor' => $newDailyMinor,
                'change_pct' => $changePct,
                'limit_pct' => $rules->maxBudgetChangePct,
                'limit_source' => $rules->sourceOf('max_budget_change_pct'),
                'currency' => $currency,
            ],
            risk: $changePct <= 10 ? 'low' : ($changePct <= 20 ? 'medium' : 'high'),
            expiresAt: $now->modify('+3 days'),
        ));
    }
}
