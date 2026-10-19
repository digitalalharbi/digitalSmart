<?php

declare(strict_types=1);

namespace App\Application\Advertising\Command;

use App\Application\Advertising\Contract\CampaignDraftRepository;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Command\RequestApproval;
use App\Application\Operations\DTO\ApprovalInput;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Advertising\Blueprint\BlueprintTranslator;
use App\Domain\Advertising\Blueprint\CampaignBlueprint;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Operations\ApprovalKind;

/**
 * Preview a blueprint in every chosen platform's terms, or turn it into paused drafts with launch requests.
 *
 * Preview changes nothing. Creating drafts writes one paused draft per platform the goal can be planned on, and one
 * launch request per draft that a person with budget authority decides. A platform that cannot take the goal gets no
 * draft — it is reported, not forced. No platform is called.
 */
final readonly class PlanBlueprint
{
    public function __construct(
        private CampaignDraftRepository $drafts,
        private RequestApproval $requestApproval,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private IdentifierGenerator $ids,
    ) {}

    /** @return list<array<string, mixed>> */
    public function preview(CampaignBlueprint $blueprint): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        return array_map(static fn ($p): array => BlueprintTranslator::translate($blueprint, $p), $blueprint->platforms);
    }

    /** @return array{batch_id: string, drafts: list<array<string, mixed>>, skipped: list<string>} */
    public function createDrafts(CampaignBlueprint $blueprint, string $projectId): array
    {
        $this->guard->ensure(Permission::ApprovalsRequest);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        if ($visible !== null && ! in_array($projectId, $visible, true)) {
            throw ResourceNotFound::of('Project');
        }

        $project = $this->drafts->project($workspaceId, $projectId) ?? throw ResourceNotFound::of('Project');
        $batch = $this->ids->generate();
        $made = [];
        $skipped = [];

        foreach ($blueprint->platforms as $provider) {
            $translation = BlueprintTranslator::translate($blueprint, $provider);

            if ($translation['objective'] === null) {
                $skipped[] = $provider->value;

                continue;
            }

            $draftId = $this->drafts->create(
                $workspaceId, $batch, $projectId, $project['client_id'], $provider->value, $blueprint->name,
                $blueprint->goal->value, $translation['objective'], $translation['daily_budget_minor'], $blueprint->currency,
                $translation, $this->actor->userId()->value,
            );

            $approval = ($this->requestApproval)(new ApprovalInput(
                kind: ApprovalKind::CampaignLaunch,
                title: sprintf('Launch %s on %s — created paused', $blueprint->name, $provider->label()),
                rationale: sprintf(
                    'Planned from one blueprint across %d platform(s). %s objective, %s %s a day, from %s. Approving starts nothing: the campaign is made paused, by a person, on the platform.',
                    count($blueprint->platforms), (string) $translation['objective'], number_format($translation['daily_budget_minor'] / 100, 2), $blueprint->currency, $blueprint->startsOn->format('Y-m-d'),
                ),
                proposal: [
                    'provider' => $provider->value,
                    'objective' => $translation['objective'],
                    'daily_minor' => $translation['daily_budget_minor'],
                    'currency' => $blueprint->currency,
                    'starts_on' => $blueprint->startsOn->format('Y-m-d'),
                    'status_on_creation' => 'PAUSED',
                    'draft_id' => $draftId,
                ],
                clientId: $project['client_id'],
                projectId: $projectId,
            ));

            $this->drafts->attachApproval($draftId, $approval->id);
            $made[] = ['id' => $draftId, 'provider' => $provider->value, 'objective' => $translation['objective'], 'approval_id' => $approval->id, 'fields' => $translation['fields']];
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::CampaignDraftsCreated,
            auditableType: 'campaign_draft_batch',
            auditableId: $batch,
            summary: sprintf('Planned "%s" as %d paused draft(s)', $blueprint->name, count($made)),
            changes: ['platforms' => array_column($made, 'provider'), 'skipped' => $skipped, 'daily_budget_minor' => $blueprint->dailyBudgetMinor, 'currency' => $blueprint->currency],
        ));

        return ['batch_id' => $batch, 'drafts' => $made, 'skipped' => $skipped];
    }
}
