<?php

declare(strict_types=1);

namespace App\Application\Automation\Command;

use App\Application\Advertising\Contract\CampaignControlReadModel;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Automation\Contract\AutomationRuleRepository;
use App\Application\Automation\DTO\RuleInput;
use App\Application\Automation\DTO\RuleView;
use App\Application\Automation\RuleCapability;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Automation\Exception\RuleNotAllowed;
use App\Domain\Automation\RuleAction;
use App\Domain\Automation\RuleMode;
use App\Domain\Automation\TrustLevel;
use App\Domain\Dashboard\Exception\NameAlreadyUsed;
use DateTimeZone;

/**
 * Create a rule, or change one.
 *
 * Scope is checked against what the writer may see: a rule cannot be aimed at a project they have no access to,
 * which would otherwise be a way to learn that it exists. A rule can only ever raise an item in the attention
 * queue — it holds no power over a campaign — so the permission that guards it is the one that guards budgets.
 */
final readonly class SaveRule
{
    public function __construct(
        private AutomationRuleRepository $rules,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private CampaignControlReadModel $campaigns,
        private RuleCapability $capability,
    ) {}

    public function __invoke(RuleInput $input, ?string $id = null): RuleView
    {
        $this->guard->ensure(Permission::BudgetsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $previous = $id === null ? null : ($this->rules->find($workspaceId, $id) ?? throw ResourceNotFound::of('Rule'));

        if ($input->projectId !== null) {
            $visible = $this->visibility->visibleProjectIds();

            if ($visible !== null && ! in_array($input->projectId, $visible, true)) {
                throw ResourceNotFound::of('Project');
            }
        }

        /*
         * Auto-execute changes a platform without a person deciding. The owner has not authorised it for any
         * workspace, so the mode is named and refused — never stored and never silently turned into Recommend.
         */
        if ($input->mode === RuleMode::Auto) {
            throw new RuleNotAllowed('Auto-execute is not authorised for this workspace. Use Recommend: the change is proposed and a person approves it.');
        }

        /*
         * Trust is given, never assumed. A rule whose action needs more than it was given is refused, not raised;
         * level 4 — changing a platform without approval — cannot be given at all; and level 3, which opens work
         * without asking, is a workspace administrator's decision.
         */
        if (! $input->trustLevel->isAvailable()) {
            throw new RuleNotAllowed('Changing a platform without a person\'s approval is not available. The highest level is 3.');
        }

        /* Monitor takes no action at all — it shows what the rule would do — so any action may be named there. */
        if ($input->mode !== RuleMode::Monitor && ! $input->trustLevel->allows($input->action)) {
            throw new RuleNotAllowed(sprintf('That action needs trust level %d or above; this rule has %d.', $input->action->minimumTrust()->value, $input->trustLevel->value));
        }

        /*
         * Never promise what no platform will do. A bid change is wired on no platform; and a rule that names its
         * platforms must name at least one that can take its action (documented and connected, or awaiting a
         * connection that would make it so). A rule over every platform is checked campaign by campaign instead.
         */
        if ($input->mode !== RuleMode::Monitor && $input->action === RuleAction::ProposeBidChange) {
            throw new RuleNotAllowed('Bid changes are not supported on any platform yet. Choose another action or Monitor.');
        }

        if ($input->mode !== RuleMode::Monitor && $input->providers !== [] && $input->action->writeOperation() !== null) {
            $able = array_filter($input->providers, fn (string $p): bool => in_array($this->capability->of($input->action, $p, $workspaceId), ['supported', 'awaiting_credentials'], true));

            if ($able === []) {
                throw new RuleNotAllowed('None of this rule\'s platforms supports that action.');
            }
        }

        if ($input->objectLevel !== 'campaign') {
            throw new RuleNotAllowed('Rules act on campaigns. Ad-group and ad rules are not available yet.');
        }

        if ($input->action->needsPct() && ($input->actionPct === null || $input->actionPct < 1 || $input->actionPct > 50)) {
            throw new RuleNotAllowed('A budget or bid proposal names a change between 1% and 50%.');
        }

        if (! in_array($input->timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new RuleNotAllowed('Unknown time zone.');
        }

        /* Every campaign named in the scope must be one the author can see — a rule cannot reach past its author. */
        foreach ($input->campaignIds as $campaignId) {
            if ($this->campaigns->writeTarget($workspaceId, $this->visibility->visibleProjectIds(), $campaignId) === null) {
                throw ResourceNotFound::of('Campaign');
            }
        }

        if ($input->trustLevel->value >= TrustLevel::ActInternally->value) {
            $this->guard->ensure(Permission::WorkspacesUpdate);
        }

        if ($this->rules->nameTaken($workspaceId, $input->name, $id)) {
            throw NameAlreadyUsed::byARule($input->name);
        }

        $saved = $id === null
            ? $this->rules->create($workspaceId, $this->actor->userId()->value, $input)
            : $this->rules->update($workspaceId, $id, $input);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::AutomationRuleSaved,
            auditableType: 'automation_rule',
            auditableId: $saved->id,
            summary: sprintf('%s the rule "%s"', $id === null ? 'Created' : 'Changed', $saved->name),
            changes: ['kinds' => $this->kinds($previous, $saved), 'before' => $previous === null ? null : self::shape($previous), 'after' => self::shape($saved)],
        ));

        return $saved;
    }

    /** @return array<string, mixed> */
    public static function shape(RuleView $r): array
    {
        return [
            'name' => $r->name,
            'active' => $r->isActive,
            'mode' => $r->mode->value,
            'schedule' => ['every' => $r->schedule->value, 'at' => $r->scheduleTime, 'timezone' => $r->timezone, 'cooldown_hours' => $r->cooldownHours, 'for_days' => $r->forDays],
            'conditions' => ['logic' => $r->logic, 'list' => [
                ['metric' => $r->metric->value, 'operator' => $r->operator->value, 'threshold' => $r->threshold],
                ...array_map(static fn (array $c): array => ['metric' => $c['metric']->value, 'operator' => ($c['operator'] ?? $c['metric']->breachedWhen())->value, 'threshold' => $c['threshold']], $r->conditions),
            ], 'window_days' => $r->windowDays],
            'action' => ['action' => $r->action->value, 'pct' => $r->actionPct],
            'scope' => ['client' => $r->clientId, 'project' => $r->projectId, 'platforms' => $r->providers, 'accounts' => $r->accountIds, 'campaigns' => $r->campaignIds, 'level' => $r->objectLevel],
        ];
    }

    /**
     * What a person would say changed: created, enabled/disabled, mode, schedule, conditions, action, scope, name.
     *
     * @return list<string>
     */
    private function kinds(?RuleView $before, RuleView $after): array
    {
        if ($before === null) {
            return ['created'];
        }

        $a = self::shape($before);
        $b = self::shape($after);
        $kinds = [];

        if ($a['active'] !== $b['active']) {
            $kinds[] = $b['active'] ? 'enabled' : 'disabled';
        }

        foreach (['mode', 'schedule', 'conditions', 'action', 'scope', 'name'] as $key) {
            if ($a[$key] !== $b[$key]) {
                $kinds[] = $key.'_changed';
            }
        }

        return $kinds === [] ? ['edited'] : $kinds;
    }
}
