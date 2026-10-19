<?php

declare(strict_types=1);

namespace App\Application\Agents\Command;

use App\Application\Advertising\Contract\CampaignControlReadModel;
use App\Application\Agents\Contract\ProposalHistory;
use App\Application\Agents\Query\ResolveOperatingRules;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Command\RequestApproval;
use App\Application\Operations\DTO\ApprovalInput;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Operations\DTO\ProposalOrigin;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Agents\ActionRefused;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderEntityWrites;
use App\Domain\Integration\WriteOperation;
use App\Domain\Integration\WriteSupport;
use App\Domain\Operations\Exception\InvalidApprovalRequest;
use App\Domain\Operations\TaskSubjectType;

/**
 * Ask for an ad group or an ad to be paused, resumed or given a different daily budget — a proposal a person decides,
 * through the same approval as a campaign's, never a change.
 *
 * Every gate a campaign proposal passes applies here, at this entity's own level: the platform's documentation must
 * describe this change **at this level** (support for a campaign proves nothing about its ads); the entity must be in
 * a state the change makes sense for; a budget must be a daily one held on the entity itself, inside the project's
 * change limit and ceiling; and one proposal per entity at a time, none within a day of the last. The proposal names
 * the entity by its own platform id and its level, which is what the carry-out step reads — never the campaign alone.
 */
final readonly class ProposeEntityChange
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

    /** @param  'pause'|'resume'|'budget'  $change */
    public function __invoke(AdEntityType $level, string $entityId, string $change, ?int $newDailyMinor, string $reason, ProposalOrigin $origin): ApprovalView
    {
        $workspaceId = $this->actor->workspaceId()->value;
        $now = $this->clock->now();

        $target = $this->campaigns->entityWriteTarget($workspaceId, $this->visibility->visibleProjectIds(), $level, $entityId)
            ?? throw ResourceNotFound::of($level === AdEntityType::Ad ? 'Ad' : 'Ad group');

        $operation = WriteOperation::at($level, $change);
        $provider = Provider::tryFrom($target['provider']);

        if ($operation === null || $provider === null || $provider->writeSupport($operation) !== WriteSupport::Documented) {
            throw InvalidApprovalRequest::notAtThisLevel(
                $provider === null || $operation === null
                    ? 'This change is not available at this level.'
                    : (string) ProviderEntityWrites::notOffered($provider, $operation),
            );
        }

        $rules = ($this->rules)($workspaceId, $target['client_id'], $target['project_id']);
        $proposal = [
            'level' => $level->value,
            'provider' => $target['provider'],
            'external_account_id' => $target['external_account_id'],
            'external_campaign_id' => $target['external_campaign_id'],
            $level === AdEntityType::Ad ? 'external_ad_id' : 'external_ad_group_id' => $target['external_entity_id'],
            'campaign' => $target['campaign_name'],
            $level === AdEntityType::Ad ? 'ad' : 'ad_group' => $target['name'],
        ];
        $evidence = $origin->evidence;
        $risk = $origin->risk;
        $noun = $level === AdEntityType::Ad ? 'ad' : 'ad group';

        if ($change === 'budget') {
            $current = $target['budget_minor'];

            if ($target['budget_mode'] !== 'daily' || $current === null || $current <= 0 || $newDailyMinor === null) {
                throw ActionRefused::notDaily();
            }

            if ($newDailyMinor === $current) {
                throw ActionRefused::unchanged();
            }

            $changePct = (int) round(abs($newDailyMinor - $current) / $current * 100);

            if ($changePct > $rules->maxBudgetChangePct) {
                throw ActionRefused::tooLarge($rules->maxBudgetChangePct);
            }

            if ($rules->maxDailyBudgetMinor !== null && $newDailyMinor > $rules->maxDailyBudgetMinor) {
                throw ActionRefused::aboveCeiling();
            }

            $verb = $newDailyMinor > $current ? 'Raise' : 'Lower';
            $title = sprintf('%s daily budget — %s %s', $verb, $noun, $target['name']);
            $proposal += [
                'change' => sprintf('%s the %s\'s daily budget by %d%%', $verb, $noun, $changePct),
                'current' => $current / 100,
                'requested' => $newDailyMinor / 100,
                'currency' => $target['currency'] ?? '',
                'daily_budget_minor' => $newDailyMinor,
            ];
            $evidence += ['current_daily_minor' => $current, 'proposed_daily_minor' => $newDailyMinor, 'change_pct' => $changePct, 'limit_pct' => $rules->maxBudgetChangePct, 'limit_source' => $rules->sourceOf('max_budget_change_pct')];
            $risk = $changePct <= 10 ? 'low' : ($changePct <= 20 ? 'medium' : 'high');
        } else {
            $status = EntityStatus::tryFrom($target['status']);
            $pausing = $change === 'pause';

            if ($status === null || ($pausing && ! $status->isSpending()) || (! $pausing && $status !== EntityStatus::Paused)) {
                throw InvalidApprovalRequest::wrongEntityState($noun, $pausing);
            }

            $title = sprintf('%s %s %s', $pausing ? 'Pause' : 'Resume', $noun, $target['name']);
            $proposal['change'] = $pausing ? 'pause' : 'resume';
        }

        $kind = $operation->authorisedBy();

        if ($this->history->hasPending($workspaceId, $kind, $target['external_entity_id'], $level->value)) {
            throw ActionRefused::alreadyProposed();
        }

        if ($this->history->hasSince($workspaceId, $kind, $target['external_entity_id'], $now->modify('-1 day'), $level->value)) {
            throw ActionRefused::coolingDown();
        }

        return ($this->ask)(new ApprovalInput(
            kind: $kind,
            title: $title,
            rationale: $reason,
            proposal: $proposal,
            clientId: $target['client_id'],
            projectId: $target['project_id'],
            subjectType: $level === AdEntityType::Ad ? TaskSubjectType::Ad : TaskSubjectType::AdGroup,
            subjectId: $entityId,
            origin: 'agent',
            agent: $origin->agent,
            evidence: $evidence,
            risk: $risk,
            expiresAt: $origin->expiresAt,
        ));
    }
}
