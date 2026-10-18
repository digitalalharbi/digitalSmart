<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Automation;

use App\Application\Automation\Command\DeleteRule;
use App\Application\Automation\Command\SaveRule;
use App\Application\Automation\DTO\RuleInput;
use App\Application\Automation\DTO\RuleView;
use App\Application\Automation\Query\ListRules;
use App\Domain\Automation\RuleMetric;
use App\Domain\Automation\RuleSeverity;
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
        ];
    }
}
