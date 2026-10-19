<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Automation;

use App\Application\Automation\Command\DeleteRule;
use App\Application\Automation\Command\SaveRule;
use App\Application\Automation\DTO\RuleInput;
use App\Application\Automation\DTO\RuleView;
use App\Application\Automation\Query\ListRules;
use App\Domain\Automation\RuleAction;
use App\Domain\Automation\RuleMetric;
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

    public function destroy(string $rule, DeleteRule $delete): JsonResponse
    {
        $delete($rule);

        return response()->json(null, Response::HTTP_NO_CONTENT);
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
        ]);

        $metric = RuleMetric::from((string) $v['metric']);

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

                return ['metric' => $metric, 'threshold' => isset($c['threshold']) ? (float) $c['threshold'] : null];
            }, (array) ($v['conditions'] ?? []))),
            forDays: (int) ($v['for_days'] ?? 1),
            action: RuleAction::tryFrom((string) ($v['action'] ?? 'alert')) ?? RuleAction::Alert,
            actionPct: isset($v['action_pct']) ? (int) $v['action_pct'] : null,
            trustLevel: TrustLevel::from((int) ($v['trust_level'] ?? TrustLevel::Propose->value)),
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
            'conditions' => array_map(static fn (array $c): array => ['metric' => $c['metric']->value, 'metric_label' => $c['metric']->label(), 'threshold' => $c['threshold']], $r->conditions),
            'for_days' => $r->forDays,
            'action' => $r->action->value,
            'action_pct' => $r->actionPct,
            'trust_level' => $r->trustLevel->value,
        ];
    }
}
