<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Automation;

use App\Application\Automation\Command\BulkRules;
use App\Application\Automation\Command\DeleteRule;
use App\Application\Automation\Command\SaveRule;
use App\Application\Automation\DTO\RuleInput;
use App\Application\Automation\DTO\RuleView;
use App\Application\Automation\Query\GetAutomationCenter;
use App\Application\Automation\Query\GetRuleCapabilities;
use App\Application\Automation\Query\ListRules;
use App\Application\Automation\Query\SimulateRule;
use App\Domain\Automation\RuleAction;
use App\Domain\Automation\RuleMetric;
use App\Domain\Automation\RuleMode;
use App\Domain\Automation\RuleOperator;
use App\Domain\Automation\RuleSchedule;
use App\Domain\Automation\RuleSeverity;
use App\Domain\Automation\TrustLevel;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The agency's own rules over its campaigns. Thin: validation here, everything else in the use cases. */
final class AutomationRuleController extends Controller
{
    public function index(ListRules $list): JsonResponse
    {
        return response()->json([
            'data' => array_map($this->present(...), $list()),
            'meta' => [
                'metrics' => array_map(static fn (RuleMetric $m): array => [
                    'metric' => $m->value,
                    'label' => $m->label(),
                    'needs_threshold' => $m->needsThreshold(),
                    'breached_when' => $m->breachedWhen()->value,
                ], RuleMetric::cases()),
                'modes' => RuleMode::values(),
                'schedules' => RuleSchedule::values(),
                'units' => array_combine(RuleMetric::values(), array_map(static fn (RuleMetric $m): string => $m->unit(), RuleMetric::cases())),
                'actions' => array_map(static fn (RuleAction $a): array => ['action' => $a->value, 'minimum_trust' => $a->minimumTrust()->value], RuleAction::cases()),
                'trust_levels' => array_map(static fn (TrustLevel $l): array => ['level' => $l->value, 'available' => $l->isAvailable()], TrustLevel::cases()),
            ],
        ]);
    }

    public function store(Request $request, SaveRule $save): JsonResponse
    {
        return response()->json(['data' => $this->present($save($this->input($request)))], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $rule, SaveRule $save): JsonResponse
    {
        return response()->json(['data' => $this->present($save($this->input($request), $rule))]);
    }

    public function simulate(Request $request, SimulateRule $simulate): JsonResponse
    {
        return response()->json(['data' => $simulate($this->input($request))]);
    }

    public function summary(Request $request, GetAutomationCenter $center): JsonResponse
    {
        return response()->json(['data' => $center->summary($this->days($request))]);
    }

    public function history(Request $request, GetAutomationCenter $center): JsonResponse
    {
        $filters = [];

        foreach (['rule_id', 'result', 'provider', 'client_id', 'project_id'] as $key) {
            $value = $request->query($key);
            $filters[$key] = is_string($value) && $value !== '' ? $value : null;
        }

        return response()->json(['data' => $center->history($filters, $this->days($request))]);
    }

    public function changeLog(Request $request, GetAutomationCenter $center): JsonResponse
    {
        return response()->json(['data' => $center->changeLog($this->days($request))]);
    }

    public function bulk(Request $request, BulkRules $bulk): JsonResponse
    {
        /** @var array{ids: list<string>, operation: 'enable'|'disable'|'archive'|'mode', mode?: string} $v */
        $v = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.BulkRules::MAX],
            'ids.*' => ['uuid'],
            'operation' => ['required', 'in:enable,disable,archive,mode'],
            /* Auto is not offered in bulk; a single save refuses it anyway. */
            'mode' => ['required_if:operation,mode', 'in:monitor,recommend'],
        ]);

        return response()->json(['data' => $bulk($v['ids'], $v['operation'], isset($v['mode']) ? RuleMode::from($v['mode']) : null)]);
    }

    public function capabilities(GetRuleCapabilities $capabilities): JsonResponse
    {
        return response()->json(['data' => $capabilities()]);
    }

    public function destroy(string $rule, DeleteRule $delete): JsonResponse
    {
        $delete($rule);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function days(Request $request): int
    {
        $days = $request->integer('days', 7);

        return in_array($days, [1, 7, 30, 90], true) ? $days : 7;
    }

    private function input(Request $request): RuleInput
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'metric' => ['required', 'in:'.implode(',', RuleMetric::values())],
            'threshold' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'window_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'severity' => ['sometimes', 'in:'.implode(',', RuleSeverity::values())],
            'is_active' => ['sometimes', 'boolean'],
            'client_id' => ['sometimes', 'nullable', 'uuid'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'conditions' => ['sometimes', 'array', 'max:3'],
            'conditions.*.metric' => ['required', 'in:'.implode(',', RuleMetric::values())],
            'conditions.*.threshold' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'for_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'action' => ['sometimes', 'in:'.implode(',', RuleAction::values())],
            'action_pct' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'trust_level' => ['sometimes', 'integer', 'min:0', 'max:4'],
            'mode' => ['sometimes', 'in:'.implode(',', RuleMode::values())],
            'logic' => ['sometimes', 'in:all,any'],
            'operator' => ['sometimes', 'in:above,below'],
            'conditions.*.operator' => ['sometimes', 'in:above,below'],
            'providers' => ['sometimes', 'array', 'max:7'],
            'providers.*' => ['in:meta,google_ads,tiktok,snapchat,x,linkedin,openai'],
            'account_ids' => ['sometimes', 'array', 'max:50'],
            'account_ids.*' => ['string', 'max:120'],
            'campaign_ids' => ['sometimes', 'array', 'max:50'],
            'campaign_ids.*' => ['uuid'],
            'object_level' => ['sometimes', 'in:campaign,ad_group,ad'],
            'schedule' => ['sometimes', 'in:'.implode(',', RuleSchedule::values())],
            'schedule_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'cooldown_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'min_spend' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000'],
            'min_age_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'min_conversions' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $metric = RuleMetric::from((string) $v['metric']);
        $action = RuleAction::tryFrom((string) ($v['action'] ?? 'alert')) ?? RuleAction::Alert;

        /*
         * The mode is what a person chooses; the trust level follows from it on the existing ladder. A request that
         * names only a trust level (older clients) gets the mode that level stands for.
         */
        if (isset($v['mode'])) {
            $mode = RuleMode::from((string) $v['mode']);
            $trust = $mode->trust($action);
        } else {
            $trust = TrustLevel::from((int) ($v['trust_level'] ?? TrustLevel::Propose->value));
            $mode = $action === RuleAction::Alert || $trust === TrustLevel::Watch ? RuleMode::Monitor : RuleMode::Recommend;
        }

        if ($metric->needsThreshold() && ! isset($v['threshold'])) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'That rule needs a limit to compare against.');
        }

        return new RuleInput(
            name: trim((string) $v['name']),
            metric: $metric,
            threshold: isset($v['threshold']) ? (float) $v['threshold'] : null,
            windowDays: (int) ($v['window_days'] ?? 7),
            severity: RuleSeverity::tryFrom((string) ($v['severity'] ?? '')) ?? RuleSeverity::NeedsAttention,
            isActive: (bool) ($v['is_active'] ?? true),
            clientId: isset($v['client_id']) ? (string) $v['client_id'] : null,
            projectId: isset($v['project_id']) ? (string) $v['project_id'] : null,
            conditions: array_values(array_map(static function (mixed $c): array {
                /** @var array{metric: string, threshold?: float|int|string|null} $c */
                $metric = RuleMetric::from($c['metric']);

                if ($metric->needsThreshold() && ! isset($c['threshold'])) {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Each condition needs a limit to compare against.');
                }

                return [
                    'metric' => $metric,
                    'threshold' => isset($c['threshold']) ? (float) $c['threshold'] : null,
                    'operator' => RuleOperator::tryFrom((string) ($c['operator'] ?? '')) ?? $metric->breachedWhen(),
                ];
            }, (array) ($v['conditions'] ?? []))),
            forDays: (int) ($v['for_days'] ?? 1),
            action: $action,
            actionPct: isset($v['action_pct']) ? (int) $v['action_pct'] : null,
            trustLevel: $trust,
            mode: $mode,
            logic: ($v['logic'] ?? 'all') === 'any' ? 'any' : 'all',
            operator: RuleOperator::tryFrom((string) ($v['operator'] ?? '')),
            providers: array_values(array_map('strval', (array) ($v['providers'] ?? []))),
            accountIds: array_values(array_map('strval', (array) ($v['account_ids'] ?? []))),
            campaignIds: array_values(array_map('strval', (array) ($v['campaign_ids'] ?? []))),
            objectLevel: (string) ($v['object_level'] ?? 'campaign'),
            schedule: RuleSchedule::tryFrom((string) ($v['schedule'] ?? 'hourly')) ?? RuleSchedule::Hourly,
            scheduleTime: isset($v['schedule_time']) ? (string) $v['schedule_time'] : null,
            timezone: (string) ($v['timezone'] ?? 'Asia/Riyadh'),
            cooldownHours: (int) ($v['cooldown_hours'] ?? 24),
            minSpend: isset($v['min_spend']) ? (float) $v['min_spend'] : null,
            minAgeDays: isset($v['min_age_days']) ? (int) $v['min_age_days'] : null,
            minConversions: isset($v['min_conversions']) ? (float) $v['min_conversions'] : null,
        );
    }

    /** @return array<string, mixed> */
    private function present(RuleView $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'metric' => $r->metric->value,
            'metric_label' => $r->metric->label(),
            'operator' => $r->operator->value,
            'threshold' => $r->threshold,
            'window_days' => $r->windowDays,
            'severity' => $r->severity->value,
            'is_active' => $r->isActive,
            'client' => $r->clientId === null ? null : ['id' => $r->clientId, 'name' => $r->clientName],
            'project' => $r->projectId === null ? null : ['id' => $r->projectId, 'name' => $r->projectName],
            'author_name' => $r->authorName,
            'conditions' => array_map(static fn (array $c): array => ['metric' => $c['metric']->value, 'metric_label' => $c['metric']->label(), 'operator' => ($c['operator'] ?? $c['metric']->breachedWhen())->value, 'threshold' => $c['threshold']], $r->conditions),
            'for_days' => $r->forDays,
            'action' => $r->action->value,
            'action_pct' => $r->actionPct,
            'trust_level' => $r->trustLevel->value,
            'mode' => $r->mode->value,
            'logic' => $r->logic,
            'providers' => $r->providers,
            'account_ids' => $r->accountIds,
            'campaign_ids' => $r->campaignIds,
            'object_level' => $r->objectLevel,
            'schedule' => $r->schedule->value,
            'schedule_time' => $r->scheduleTime,
            'timezone' => $r->timezone,
            'cooldown_hours' => $r->cooldownHours,
            'min_spend' => $r->minSpend,
            'min_age_days' => $r->minAgeDays,
            'min_conversions' => $r->minConversions,
            'last_run_at' => $r->lastRunAt?->format(DATE_ATOM),
            'last_result' => $r->lastResult,
        ];
    }
}
