<?php

declare(strict_types=1);

namespace App\Application\Advertising\Command;

use App\Application\Advertising\Contract\CampaignControlReadModel;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Command\RequestApproval;
use App\Application\Operations\DTO\ApprovalInput;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Advertising\EntityStatus;
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
    ) {}

    public function __invoke(string $campaignId, WriteOperation $operation, string $reason): ApprovalView
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
        ));
    }
}
