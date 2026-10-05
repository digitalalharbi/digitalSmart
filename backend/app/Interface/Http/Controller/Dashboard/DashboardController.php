<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Dashboard;

use App\Application\Dashboard\Command\DeleteDashboard;
use App\Application\Dashboard\Command\DuplicateDashboard;
use App\Application\Dashboard\Command\SaveDashboard;
use App\Application\Dashboard\DTO\DashboardView;
use App\Application\Dashboard\Query\GetDashboard;
use App\Application\Dashboard\Query\ListDashboards;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Dashboard\DashboardRequest;
use App\Interface\Http\Resource\Dashboard\DashboardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DashboardController extends Controller
{
    public function index(ListDashboards $listDashboards): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (DashboardView $dashboard): array => DashboardResource::toArray($dashboard),
                $listDashboards(),
            ),
        ]);
    }

    public function show(string $dashboard, GetDashboard $getDashboard): JsonResponse
    {
        return response()->json(['data' => DashboardResource::toArray($getDashboard($dashboard))]);
    }

    public function store(DashboardRequest $request, SaveDashboard $saveDashboard): JsonResponse
    {
        return response()->json(
            ['data' => DashboardResource::toArray($saveDashboard($request->toInput()))],
            Response::HTTP_CREATED,
        );
    }

    public function update(string $dashboard, DashboardRequest $request, SaveDashboard $saveDashboard): JsonResponse
    {
        return response()->json([
            'data' => DashboardResource::toArray($saveDashboard($request->toInput(), $dashboard)),
        ]);
    }

    public function destroy(string $dashboard, DeleteDashboard $deleteDashboard): JsonResponse
    {
        $deleteDashboard($dashboard);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Copy a dashboard into one the caller owns.
     *
     * How a shared dashboard gets adapted without being changed for everyone using
     * it. The copy is always private, whatever the original was.
     */
    public function duplicate(
        string $dashboard,
        Request $request,
        DuplicateDashboard $duplicateDashboard,
    ): JsonResponse {
        $name = $request->string('name')->value() ?: null;

        return response()->json(
            ['data' => DashboardResource::toArray($duplicateDashboard($dashboard, $name))],
            Response::HTTP_CREATED,
        );
    }
}
