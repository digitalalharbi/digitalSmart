<?php

declare(strict_types=1);

namespace App\Application\Automation\Query;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Application\Automation\DTO\RuleInput;
use App\Application\Automation\DTO\RuleView;
use App\Application\Automation\RuleEvaluator;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use App\Domain\Automation\RuleAction;
use App\Domain\Automation\RuleMode;
use App\Domain\Integration\Provider;
use App\Domain\Integration\WriteSupport;

/**
 * A dry run: which campaigns a rule — saved or still a draft — would act on right now, with every condition's figure,
 * the action it would take, the value before and after, and whether the platform could take it.
 *
 * Writes nothing: no proposal, no task, no streak, no history. The scope is the reader's own — a draft cannot be used
 * to look at campaigns its author could not open.
 */
final readonly class SimulateRule
{
    public function __construct(
        private GetCampaignControl $campaigns,
        private AuthorizationGuard $guard,
        private Clock $clock,
    ) {}

    /** @return array{matched: int, looked_at: int, objects: list<array<string, mixed>>} */
    public function __invoke(RuleInput $input): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $rule = self::view($input);
        $today = $this->clock->now();
        $rows = $this->campaigns->assessAll(new CampaignControlFilter(
            clientIds: $rule->clientId === null ? [] : [$rule->clientId],
            projectIds: $rule->projectId === null ? [] : [$rule->projectId],
            providers: $rule->providers,
            externalAccountIds: $rule->accountIds,
            campaignIds: $rule->campaignIds,
        ), $rule->windowDays)['rows'];

        $objects = [];

        foreach ($rows as $row) {
            $evaluation = RuleEvaluator::evaluate($rule, $row, $today);

            if (! $evaluation['holds']) {
                continue;
            }

            $objects[] = [
                'campaign_id' => $row->campaignId,
                'campaign' => $row->campaignName,
                'provider' => $row->provider,
                'client' => $row->clientName,
                'project' => $row->projectName,
                'currency' => $row->currency,
                'values' => $evaluation['values'],
                'action' => $rule->mode === RuleMode::Monitor ? 'notify' : $rule->action->value,
                'before' => ['daily_budget' => $row->budgetMinor === null ? null : $row->budgetMinor / 100, 'status' => $row->status->value],
                'after' => self::after($rule, $row),
                'capability' => self::capability($rule, $row),
            ];
        }

        return ['matched' => count($objects), 'looked_at' => count($rows), 'objects' => array_slice($objects, 0, 100)];
    }

    public static function view(RuleInput $input, string $id = 'draft'): RuleView
    {
        return new RuleView(
            id: $id,
            name: $input->name,
            metric: $input->metric,
            operator: $input->operator ?? $input->metric->breachedWhen(),
            threshold: $input->threshold,
            windowDays: $input->windowDays,
            severity: $input->severity,
            isActive: $input->isActive,
            clientId: $input->clientId,
            clientName: null,
            projectId: $input->projectId,
            projectName: null,
            authorName: null,
            conditions: $input->conditions,
            forDays: $input->forDays,
            action: $input->action,
            actionPct: $input->actionPct,
            trustLevel: $input->trustLevel,
            mode: $input->mode,
            logic: $input->logic,
            providers: $input->providers,
            accountIds: $input->accountIds,
            campaignIds: $input->campaignIds,
            objectLevel: $input->objectLevel,
            schedule: $input->schedule,
            scheduleTime: $input->scheduleTime,
            timezone: $input->timezone,
            cooldownHours: $input->cooldownHours,
        );
    }

    /** @return array<string, mixed> */
    private static function after(RuleView $rule, CampaignControlRow $row): array
    {
        if ($rule->mode === RuleMode::Monitor) {
            return [];
        }

        return match ($rule->action) {
            RuleAction::ProposePause => ['status' => 'paused'],
            RuleAction::ProposeResume => ['status' => 'active'],
            RuleAction::ProposeBudgetCut, RuleAction::ProposeBudgetIncrease => $row->budgetMinor === null ? [] : [
                'daily_budget' => round($row->budgetMinor * (100 + ($rule->action === RuleAction::ProposeBudgetIncrease ? 1 : -1) * (int) $rule->actionPct) / 100) / 100,
            ],
            default => [],
        };
    }

    /** supported · unsupported (with the reason) — the same verdict the routine would reach before acting. */
    private static function capability(RuleView $rule, CampaignControlRow $row): string
    {
        if ($rule->mode === RuleMode::Monitor || in_array($rule->action, [RuleAction::Alert, RuleAction::Task], true)) {
            return 'supported';
        }

        if ($rule->action === RuleAction::ProposeBidChange) {
            return 'unsupported:BID_WRITE_NOT_VERIFIED';
        }

        if (in_array($rule->action, [RuleAction::ProposeBudgetCut, RuleAction::ProposeBudgetIncrease], true) && ($row->budgetMinor === null || $row->budgetMinor <= 0)) {
            return 'unsupported:NO_DAILY_BUDGET';
        }

        $operation = $rule->action->writeOperation();
        $provider = Provider::tryFrom($row->provider);

        return $operation !== null && $provider !== null && $provider->writeSupport($operation) === WriteSupport::Documented
            ? 'supported'
            : 'unsupported:PROVIDER_WRITE_NOT_DOCUMENTED';
    }
}
