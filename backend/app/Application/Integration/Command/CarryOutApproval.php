<?php

declare(strict_types=1);

namespace App\Application\Integration\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Integration\Contract\ProviderWriterRegistry;
use App\Application\Integration\DTO\WriteRequest;
use App\Application\Operations\Contract\ApprovalRepository;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Integration\Exception\WriteRefused;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderMcp;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Integration\WriteOperation;
use App\Domain\Integration\WriteOutcome;
use App\Domain\Integration\WriteTarget;
use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\EnactmentState;
use App\Domain\Workspace\WorkspaceId;

/**
 * Carry out an approved change on the platform.
 *
 * The only place in this product that changes anything outside it. Seven gates, in this order, and each one
 * exists because of a specific way this could go wrong:
 *
 *  1. **The permission.** `provider_writes.execute`, separate from `approvals.decide`, because deciding a change
 *     should happen and being the person who makes it happen are two acts — which is the whole of this product's
 *     approvals argument.
 *  2. **The request is approved.** Not pending, not declined, not withdrawn.
 *  3. **The enactment is still outstanding.** A change already carried out is not carried out twice.
 *  4. **The proposal still matches what was approved**, by fingerprint. Somebody amending a proposal after
 *     approval and then executing it would be executing something nobody approved — and `ProposalFingerprint`
 *     already exists for exactly this.
 *  5. **The proposal names a platform campaign.** Most approvals do not, and those are refused with that
 *     sentence rather than offered a button that guesses which campaign was meant.
 *  6. **The provider's write is documented.** `WriteSupport::allowsAttempt()` — this product does not guess at an
 *     endpoint it has not read in the provider's own documentation.
 *  7. **The connection holds the capability**, and exists at all.
 *
 * Then the write, and then the part that matters most: **the outcome is recorded whatever it is**. The attempt is
 * audited before the request leaves, so an outcome of `unknown` leaves a trail; and the enactment is marked
 * confirmed only when the provider reported the new state on a read-back.
 */
final readonly class CarryOutApproval
{
    public function __construct(
        private ApprovalRepository $approvals,
        private ProviderConnectionRepository $connections,
        private ProviderWriterRegistry $writers,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /**
     * @return array{approval: ApprovalView, outcome: WriteOutcome}
     */
    public function __invoke(string $requestId): array
    {
        $this->guard->ensure(Permission::ProviderWritesExecute);

        $workspaceId = $this->actor->workspaceId()->value;

        $approval = $this->approvals->find($workspaceId, $this->visibility->visibleProjectIds(), $requestId)
            ?? throw ResourceNotFound::of('Approval request');

        if ($approval->state !== ApprovalState::Approved) {
            throw WriteRefused::notApproved();
        }

        if ($approval->enactment->isSettled()) {
            throw WriteRefused::alreadySettled();
        }

        /*
         * The fingerprint the decision was taken against. `DecideApproval` stores it; if the proposal has been
         * amended since, this is a different change and needs a different approval.
         */
        if (! $approval->proposalMatchesDecision()) {
            throw WriteRefused::proposalChanged();
        }

        $operation = $this->operationFor($approval);
        $target = WriteTarget::fromProposal($approval->proposal, $operation);

        if ($target === null) {
            throw WriteRefused::proposalNamesNoCampaign();
        }

        $support = $target->provider->writeSupport($operation);

        /*
         * The execution route: a write goes through the platform's direct API, where its documentation was read and
         * where the change is confirmed by reading it back — never through a platform's MCP server, whatever that
         * server's documentation offers, until a read-back exists on that path too. See `ProviderMcp`.
         */
        if (! $support->allowsAttempt() || ProviderMcp::route($target->provider, 'write', false, true)['route'] !== 'direct_api') {
            throw WriteRefused::providerNotDocumented($target->provider, $support);
        }

        $connection = $this->connections->agencyConnection(
            new WorkspaceId($workspaceId),
            $target->provider,
        ) ?? throw WriteRefused::notConnected($target->provider);

        if (! $target->provider->supports($operation->requires())) {
            throw WriteRefused::capabilityMissing($target->provider, $operation);
        }

        $request = new WriteRequest(
            provider: $target->provider,
            operation: $operation,
            credentials: $connection->credentials(),
            externalAccountId: $target->externalAccountId,
            externalCampaignId: $target->externalCampaignId,
            dailyBudgetMinor: $target->dailyBudgetMinor,
            currency: $target->currency,
        );

        /*
         * Audited **before** the request leaves. An outcome of `unknown` — a timeout, a dropped connection — has
         * no response to record, and the only thing that can tell somebody a request was sent at all is an entry
         * written before it was.
         */
        $this->record(AuditAction::ProviderWriteAttempted, $approval, $operation, $target->provider, [
            'external_account_id' => $target->externalAccountId,
            'external_campaign_id' => $target->externalCampaignId,
            'daily_budget_minor' => $target->dailyBudgetMinor,
            'reference' => $target->provider->writeReference($operation),
        ]);

        $outcome = $this->writers->for($request)->execute($request);

        $this->record(
            $outcome->isConfirmed ? AuditAction::ProviderWriteConfirmed : AuditAction::ProviderWriteFailed,
            $approval,
            $operation,
            $target->provider,
            [
                'confirmed_state' => $outcome->confirmedState,
                'failure' => $outcome->failure?->value,
                'provider_message' => $outcome->providerMessage,
                'provider_request_id' => $outcome->providerRequestId,
                /*
                 * The source state of the write itself. A write against sandbox credentials is a SANDBOX entry
                 * for ever; an audit trail recording a simulated pause as a real one is worse than none.
                 */
                'source' => $outcome->source->value,
                'safe_to_retry' => $outcome->failure?->isSafeToRetry(),
            ],
        );

        if (! $outcome->isConfirmed) {
            /*
             * The enactment is left outstanding. It is not abandoned — nobody has decided not to make the change
             * — and it is certainly not confirmed. A failed attempt leaves the request exactly where it was,
             * which is what lets somebody retry it or go and do it by hand.
             */
            return ['approval' => $approval, 'outcome' => $outcome];
        }

        $confirmed = $this->approvals->recordEnactment(
            $workspaceId,
            $requestId,
            EnactmentState::Confirmed,
            /*
             * The note says who confirmed it, and it was the provider rather than a person. The distinction is
             * the point of this phase: `RecordEnactment` writes a claim with a name on it, and this writes a
             * read-back.
             */
            sprintf('Carried out by digitalSmart. %s', $outcome->summary()),
            $this->actor->userId()->value,
            $this->clock->now(),
        );

        return ['approval' => $confirmed, 'outcome' => $outcome];
    }

    /**
     * The operation this approval authorises.
     *
     * Derived from the kind, and from the proposal only where the kind is ambiguous: a campaign pause is a pause,
     * a spend change is a budget change, and a launch is a resume. A proposal field naming the operation would be
     * a second source of truth that the approval's own kind could contradict.
     */
    private function operationFor(ApprovalView $approval): WriteOperation
    {
        foreach (WriteOperation::cases() as $operation) {
            if ($operation->authorisedBy() === $approval->kind) {
                return $operation;
            }
        }

        throw WriteRefused::kindNotExecutable($approval->kind);
    }

    /** @param  array<string, mixed>  $changes */
    private function record(
        AuditAction $action,
        ApprovalView $approval,
        WriteOperation $operation,
        Provider $provider,
        array $changes,
    ): void {
        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: $action,
            auditableType: 'approval_request',
            auditableId: $approval->id,
            summary: sprintf('%s on %s: %s', $operation->label(), $provider->label(), $approval->title),
            changes: [
                'operation' => $operation->value,
                'provider' => $provider->value,
                'approval_kind' => $approval->kind->value,
                ...$changes,
            ],
        ));
    }
}
