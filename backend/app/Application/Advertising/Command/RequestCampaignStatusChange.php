<?php

declare(strict_types=1);

namespace App\Application\Advertising\Command;

use App\Application\Advertising\Contract\CampaignControlReadModel;
use App\Application\Agents\Contract\ProposalHistory;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Command\RequestApproval;
use App\Application\Operations\DTO\ApprovalInput;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Operations\DTO\ProposalOrigin;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Advertising\EntityStatus;
use App\Domain\Agents\ActionRefused;
use App\Domain\Integration\Provider;
use App\Domain\Integration\WriteOperation;
use App\Domain\Integration\WriteSupport;
use App\Domain\Operations\Exception\InvalidApprovalRequest;
use App\Domain\Operations\TaskSubjectType;

/**
 * Ask for a campaign to be paused or resumed.
 *
 * **This changes nothing.** It writes an approval request whose proposal names the platform's own ids for the
 * campaign — the thing the carry-out step refuses to guess at — so the person who decides is reading a proposal
 * built by this product from its own records rather than one a browser typed. Deciding and carrying out are two
 * more acts, by two more permissions, and neither happens here.
 *
 * Refused before anything is written when the platform's write is not documented, or when the campaign is not
 * in a state the change makes sense for: asking to pause what is already paused would be a request nobody
 * could carry out and everybody would have to read.
 */
final readonly class RequestCampaignStatusChange
{
    public function __construct(
        private CampaignControlReadModel $campaigns,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private RequestApproval $ask,
        private ProposalHistory $history,
        private Clock $clock,
    ) {}

    public function __invoke(string $campaignId, WriteOperation $operation, string $reason, ?ProposalOrigin $origin = null): ApprovalView
    {
        if (! in_array($operation, [WriteOperation::PauseCampaign, WriteOperation::ResumeCampaign], true)) {
            throw InvalidApprovalRequest::notAStatusChange();
        }

        $target = $this->campaigns->writeTarget(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $campaignId,
        ) ?? throw ResourceNotFound::of('Campaign');

        $provider = Provider::tryFrom($target['provider']);

        if ($provider === null || $provider->writeSupport($operation) !== WriteSupport::Documented) {
            throw InvalidApprovalRequest::providerNotDocumented(
                $provider === null ? $target['provider'] : $provider->label(),
            );
        }

        $status = EntityStatus::tryFrom($target['status']);
        $pausing = $operation === WriteOperation::PauseCampaign;

        if ($status === null || ($pausing && ! $status->isSpending()) || (! $pausing && $status !== EntityStatus::Paused)) {
            throw InvalidApprovalRequest::wrongState($pausing);
        }

        if ($origin !== null) {
            /*
             * An agent never stacks a second request on a campaign that already has one waiting, and does not come
             * back to the same campaign within a day. Both are limits in code: the agent is not asked to remember.
             */
            $workspaceId = $this->actor->workspaceId()->value;

            if ($this->history->hasPending($workspaceId, $operation->authorisedBy(), $target['external_campaign_id'])) {
                throw ActionRefused::alreadyProposed();
            }

            if ($this->history->hasSince($workspaceId, $operation->authorisedBy(), $target['external_campaign_id'], $this->clock->now()->modify('-1 day'))) {
                throw ActionRefused::coolingDown();
            }
        }

        return ($this->ask)(new ApprovalInput(
            kind: $operation->authorisedBy(),
            title: sprintf('%s %s', $pausing ? 'Pause' : 'Resume', $target['name']),
            rationale: $reason,
            proposal: [
                'provider' => $target['provider'],
                'external_account_id' => $target['external_account_id'],
                'external_campaign_id' => $target['external_campaign_id'],
                'campaign' => $target['name'],
                'change' => $pausing ? 'pause' : 'resume',
            ],
            clientId: $target['client_id'],
            projectId: $target['project_id'],
            subjectType: TaskSubjectType::Campaign,
            subjectId: $campaignId,
            origin: $origin === null ? 'person' : 'agent',
            agent: $origin?->agent,
            evidence: $origin?->evidence,
            risk: $origin?->risk,
            expiresAt: $origin?->expiresAt,
        ));
    }
}
