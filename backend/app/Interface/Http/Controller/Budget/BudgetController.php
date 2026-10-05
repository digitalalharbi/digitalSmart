<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Budget;

use App\Application\Budget\Command\ApproveBudget;
use App\Application\Budget\Command\ArchiveBudget;
use App\Application\Budget\Command\DeleteBudget;
use App\Application\Budget\Command\SaveAllocations;
use App\Application\Budget\Command\SaveBudget;
use App\Application\Budget\DTO\BudgetView;
use App\Application\Budget\Query\GetBudget;
use App\Application\Budget\Query\ListBudgets;
use App\Domain\Budget\BudgetStatus;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Budget\AllocationsRequest;
use App\Interface\Http\Request\Budget\BudgetRequest;
use App\Interface\Http\Resource\Budget\BudgetResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BudgetController extends Controller
{
    public function index(Request $request, ListBudgets $listBudgets): JsonResponse
    {
        /** @var list<string> $requested */
        $requested = (array) $request->query('status', []);
        $statuses = [];

        foreach ($requested as $value) {
            $status = is_string($value) ? BudgetStatus::tryFrom($value) : null;

            if ($status instanceof BudgetStatus) {
                $statuses[] = $status;
            }
        }

        $clientId = $request->string('client_id')->value() ?: null;

        return response()->json([
            'data' => array_map(
                static fn (BudgetView $budget): array => BudgetResource::toArray($budget),
                $listBudgets($statuses, $clientId),
            ),
        ]);
    }

    public function show(string $budget, GetBudget $getBudget): JsonResponse
    {
        return response()->json(['data' => BudgetResource::toArray($getBudget($budget))]);
    }

    public function store(BudgetRequest $request, SaveBudget $saveBudget): JsonResponse
    {
        return response()->json(
            ['data' => BudgetResource::toArray($saveBudget($request->toInput()))],
            Response::HTTP_CREATED,
        );
    }

    public function update(string $budget, BudgetRequest $request, SaveBudget $saveBudget): JsonResponse
    {
        return response()->json(['data' => BudgetResource::toArray($saveBudget($request->toInput(), $budget))]);
    }

    public function destroy(string $budget, DeleteBudget $deleteBudget): JsonResponse
    {
        $deleteBudget($budget);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Commit to the figure.
     *
     * A POST rather than a status field on the update, because approval is an act with
     * its own permission and its own audit entry — not a property somebody can set in
     * passing while renaming a budget.
     */
    public function approve(string $budget, ApproveBudget $approveBudget): JsonResponse
    {
        return response()->json(['data' => BudgetResource::toArray($approveBudget($budget))]);
    }

    public function archive(string $budget, ArchiveBudget $archiveBudget): JsonResponse
    {
        return response()->json(['data' => BudgetResource::toArray($archiveBudget($budget))]);
    }

    /** Replace the whole allocation tree. */
    public function allocations(string $budget, AllocationsRequest $request, SaveAllocations $saveAllocations): JsonResponse
    {
        return response()->json(['data' => BudgetResource::toArray($saveAllocations($budget, $request->toInput()))]);
    }
}
