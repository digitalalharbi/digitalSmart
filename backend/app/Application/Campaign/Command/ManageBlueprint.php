<?php

declare(strict_types=1);

namespace App\Application\Campaign\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Campaign\Contract\BlueprintRepository;
use App\Application\Campaign\DTO\BlueprintInput;
use App\Application\Campaign\DTO\BlueprintView;
use App\Application\Campaign\Exception\BlueprintRefused;
use App\Application\Saas\EntitlementGuard;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Advertising\Blueprint\BlueprintStatus;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Saas\Feature;

/**
 * Start, edit, submit, return and cancel a campaign plan. Nothing here calls a platform.
 *
 * Every reference is checked against the project: an ad account must be bound to it (the only way a project may touch an
 * account), a tracking link must be the project's, a conversion and creatives must be its client's. Editing a plan under review
 * sends it back to draft and bumps its version, so an approval can only ever name what its approver read.
 */
final readonly class ManageBlueprint
{
    public function __construct(
        private BlueprintRepository $blueprints,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private EntitlementGuard $entitlements,
        private AuditRecorder $audit,
        private IdentifierGenerator $ids,
    ) {}

    public function create(BlueprintInput $input): BlueprintView
    {
        $this->guard->ensure(Permission::CampaignBlueprintsCreate);
        $this->entitlements->ensureFeature(Feature::CampaignLaunch);

        [$clientId, $targets] = $this->checked($input);
        $id = $this->ids->generate();
        $this->blueprints->create($id, $this->workspace(), $clientId, $input, $targets, $this->actor->userId()->value);
        $this->record(AuditAction::CampaignBlueprintCreated, $id, sprintf('Started the campaign plan "%s".', $input->name), ['providers' => array_values(array_unique($targets))]);

        return $this->load($id);
    }

    public function update(string $id, BlueprintInput $input): BlueprintView
    {
        $this->guard->ensure(Permission::CampaignBlueprintsUpdate);
        $current = $this->load($id);
        $status = BlueprintStatus::from($current->status);

        if (! $status->isEditable()) {
            throw BlueprintRefused::notEditable($current->status);
        }

        if ($input->projectId !== $current->projectId) {
            throw BlueprintRefused::referenceOutsideProject('project');
        }

        [, $targets] = $this->checked($input);
        $this->blueprints->update($this->workspace(), $id, $input, $targets);
        $this->record(AuditAction::CampaignBlueprintUpdated, $id, sprintf('Edited the campaign plan "%s" (version %d).', $input->name, $current->version + 1), [
            'version' => $current->version + 1,
            'returned_to_draft' => $status === BlueprintStatus::ReadyForReview,
        ]);

        return $this->load($id);
    }

    public function submit(string $id): BlueprintView
    {
        $this->guard->ensure(Permission::CampaignBlueprintsSubmit);
        $current = $this->load($id);
        $this->ensureMove($current, BlueprintStatus::ReadyForReview);

        $missing = self::missing($current);

        if ($missing !== []) {
            throw BlueprintRefused::incomplete($missing);
        }

        $this->blueprints->setStatus($this->workspace(), $id, BlueprintStatus::ReadyForReview, null, $this->actor->userId()->value);
        $this->record(AuditAction::CampaignBlueprintSubmitted, $id, sprintf('Submitted "%s" for review (version %d).', $current->name, $current->version), ['version' => $current->version]);

        return $this->load($id);
    }

    public function returnToDraft(string $id, string $reason): BlueprintView
    {
        $this->guard->ensure(Permission::CampaignBlueprintsUpdate);

        return $this->move($id, BlueprintStatus::Draft, $reason, AuditAction::CampaignBlueprintReturned, 'Returned "%s" to draft.');
    }

    public function cancel(string $id, string $reason): BlueprintView
    {
        $this->guard->ensure(Permission::CampaignBlueprintsUpdate);

        return $this->move($id, BlueprintStatus::Cancelled, $reason, AuditAction::CampaignBlueprintCancelled, 'Cancelled the campaign plan "%s".');
    }

    /**
     * What a plan still needs before it can be reviewed. Creatives and a conversion are not required here — a plan can be
     * reviewed before the creative is finished — and the preflight says what each platform will need.
     *
     * @return list<string>
     */
    public static function missing(BlueprintView $b): array
    {
        $missing = [];

        if ($b->targets === []) {
            $missing[] = 'at least one ad account';
        }

        if ($b->destination() === null) {
            $missing[] = 'a tracking link or a landing page';
        }

        if (($b->targeting['countries'] ?? []) === []) {
            $missing[] = 'at least one country';
        }

        if (trim((string) ($b->adCopy['headline'] ?? '')) === '') {
            $missing[] = 'a headline';
        }

        return $missing;
    }

    private function move(string $id, BlueprintStatus $to, string $reason, AuditAction $action, string $summary): BlueprintView
    {
        if (trim($reason) === '') {
            throw BlueprintRefused::reasonRequired();
        }

        $current = $this->load($id);
        $this->ensureMove($current, $to);
        $this->blueprints->setStatus($this->workspace(), $id, $to, $reason, $this->actor->userId()->value);
        $this->record($action, $id, sprintf($summary, $current->name), ['from' => $current->status, 'reason' => $reason]);

        return $this->load($id);
    }

    private function ensureMove(BlueprintView $current, BlueprintStatus $to): void
    {
        if (! BlueprintStatus::from($current->status)->canMoveTo($to)) {
            throw BlueprintRefused::cannotMove($current->status, $to->value);
        }
    }

    /** @return array{0: string, 1: array<string, string>} the client, and the targets' providers */
    private function checked(BlueprintInput $input): array
    {
        $workspace = $this->workspace();
        $visible = $this->visibility->visibleProjectIds();

        if ($visible !== null && ! in_array($input->projectId, $visible, true)) {
            throw ResourceNotFound::of('Project');
        }

        $clientId = $this->blueprints->clientOf($workspace, $input->projectId) ?? throw ResourceNotFound::of('Project');
        $ids = array_values(array_unique($input->externalAccountIds));
        $targets = $this->blueprints->boundAccounts($workspace, $input->projectId, $ids);

        if (count($targets) !== count($ids)) {
            throw BlueprintRefused::accountNotBound();
        }

        if ($input->trackingLinkId !== null && ! $this->blueprints->trackingLinkInProject($workspace, $input->projectId, $input->trackingLinkId)) {
            throw BlueprintRefused::referenceOutsideProject('tracking link');
        }

        if ($input->conversionEventId !== null && ! $this->blueprints->conversionForClient($workspace, $clientId, $input->conversionEventId)) {
            throw BlueprintRefused::referenceOutsideProject('conversion');
        }

        $creatives = array_values(array_unique($input->creativeRequestIds));

        if (count($this->blueprints->creativesForClient($workspace, $clientId, $creatives)) !== count($creatives)) {
            throw BlueprintRefused::referenceOutsideProject('creative');
        }

        return [$clientId, $targets];
    }

    private function load(string $id): BlueprintView
    {
        return $this->blueprints->find($this->workspace(), $id, $this->visibility->visibleProjectIds())
            ?? throw ResourceNotFound::of('Campaign plan');
    }

    /** @param array<string, mixed> $changes */
    private function record(AuditAction $action, string $id, string $summary, array $changes): void
    {
        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: $action,
            auditableType: 'campaign_blueprint',
            auditableId: $id,
            summary: $summary,
            changes: $changes,
        ));
    }

    private function workspace(): string
    {
        return $this->actor->workspaceId()->value;
    }
}
