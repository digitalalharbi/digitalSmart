<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Budget;

use App\Application\Budget\DTO\BudgetControlRow;
use App\Application\Budget\Query\GetBudgetControl;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The finance surface: every approved budget, with pacing and risk, without opening a project.
 *
 * Risks are published with who should hear about them. Routing is by responsibility rather than by role
 * name — the audience is a *kind of work*, and the roles that hold it are whoever holds those permissions.
 */
final class BudgetControlController extends Controller
{
    /** @var array<string, array{label: string, audience: list<string>}> */
    private const RISKS = [
        'overspend' => ['label' => 'Spending ahead of plan', 'audience' => ['finance', 'project_manager']],
        'underpace' => ['label' => 'Spending behind plan', 'audience' => ['finance', 'project_manager', 'media_buyer']],
        'near_limit' => ['label' => 'Close to the approved amount', 'audience' => ['finance']],
        'unallocated' => ['label' => 'Money not yet allocated', 'audience' => ['finance', 'project_manager']],
        'platform_over' => ['label' => 'A platform is past its allocation', 'audience' => ['media_buyer', 'project_manager']],
        'campaign_over' => ['label' => 'A campaign is past its allocation', 'audience' => ['media_buyer', 'project_manager']],
    ];

    public function index(Request $request, GetBudgetControl $control): JsonResponse
    {
        $risk = $request->query('risk');

        if ($risk !== null && (! is_string($risk) || ! isset(self::RISKS[$risk]))) {
            return response()->json([
                'message' => 'Unknown risk.',
                'code' => 'invalid_risk',
                'allowed' => array_keys(self::RISKS),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $client = $request->query('client_id');
        $project = $request->query('project_id');

        $rows = $control(
            is_string($client) && $client !== '' ? $client : null,
            is_string($project) && $project !== '' ? $project : null,
        );

        if (is_string($risk)) {
            $rows = array_values(array_filter($rows, static fn (BudgetControlRow $r): bool => in_array($risk, $r->risks, true)));
        }

        /** @var array<string, array{currency: string, approved_minor: int, allocated_minor: int, spent_minor: int, unreported: int, at_risk: int}> $totals */
        $totals = [];

        foreach ($rows as $r) {
            $t = $totals[$r->currency] ?? [
                'currency' => $r->currency, 'approved_minor' => 0, 'allocated_minor' => 0,
                'spent_minor' => 0, 'unreported' => 0, 'at_risk' => 0,
            ];
            $t['approved_minor'] += $r->approvedMinor;
            $t['allocated_minor'] += $r->allocatedMinor;
            $t['spent_minor'] += $r->spentMinor ?? 0;
            $t['unreported'] += $r->spentMinor === null ? 1 : 0;
            $t['at_risk'] += $r->risks === [] ? 0 : 1;
            $totals[$r->currency] = $t;
        }

        return response()->json([
            'data' => array_map(static fn (BudgetControlRow $r): array => [
                'id' => $r->budgetId,
                'name' => $r->name,
                'client' => ['id' => $r->clientId, 'name' => $r->clientName],
                'project' => $r->projectId === null ? null : ['id' => $r->projectId, 'name' => $r->projectName],
                'currency' => $r->currency,
                'approved_minor' => $r->approvedMinor,
                'allocated_minor' => $r->allocatedMinor,
                'unallocated_minor' => $r->approvedMinor - $r->allocatedMinor,
                /* Null is "cannot be totalled" and is explained below; it is never a zero. */
                'spent_minor' => $r->spentMinor,
                'remaining_minor' => $r->remainingMinor(),
                'spend_unavailable_reason' => $r->unavailableReason,
                'pacing' => $r->pacing === null ? null : [
                    'status' => $r->pacing->status->value,
                    'status_label' => $r->pacing->status->label(),
                    'variance_percent' => $r->pacing->variancePercent,
                    'projected_minor' => $r->pacing->projectedMinor,
                    'utilisation' => $r->pacing->utilisation(),
                    'remaining_days' => $r->pacing->remainingDays,
                ],
                'period' => [
                    'start' => $r->periodStart->format('Y-m-d'),
                    'end' => $r->periodEnd->format('Y-m-d'),
                ],
                'risks' => array_map(static fn (string $key): array => [
                    'risk' => $key,
                    'label' => self::RISKS[$key]['label'],
                    'audience' => self::RISKS[$key]['audience'],
                ], $r->risks),
            ], $rows),
            'meta' => [
                'totals' => array_values($totals),
                'count' => count($rows),
                'risks' => array_map(static fn (string $key): array => ['risk' => $key] + self::RISKS[$key], array_keys(self::RISKS)),
            ],
        ]);
    }
}
