<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Dashboard;

use App\Application\Dashboard\Command\DeleteSavedView;
use App\Application\Dashboard\Command\SaveView;
use App\Application\Dashboard\Contract\SavedViewRepository;
use App\Application\Dashboard\DTO\SavedViewView;
use App\Application\Dashboard\Query\ListSavedViews;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Dashboard\SavedViewRequest;
use App\Interface\Http\Resource\Dashboard\SavedViewResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Saved analytics views for the active workspace.
 *
 * Thin by design: validate, call one use case, render. Visibility and ownership are
 * decided inside the use cases, so a new caller cannot bypass them.
 */
final class SavedViewController extends Controller
{
    public function index(ListSavedViews $listViews): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (SavedViewView $view): array => SavedViewResource::toArray($view),
                $listViews(),
            ),
        ]);
    }

    public function store(SavedViewRequest $request, SaveView $saveView, Clock $clock): JsonResponse
    {
        $view = $saveView($request->toInput($clock));

        return response()->json(['data' => SavedViewResource::toArray($view)], Response::HTTP_CREATED);
    }

    public function update(string $view, SavedViewRequest $request, SaveView $saveView, Clock $clock): JsonResponse
    {
        return response()->json([
            'data' => SavedViewResource::toArray($saveView($request->toInput($clock), $view)),
        ]);
    }

    public function destroy(string $view, DeleteSavedView $deleteView): JsonResponse
    {
        $deleteView($view);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * Record that a view was opened.
     *
     * Separate from reading it, so the ordering in the picker reflects what someone
     * actually uses rather than what a background refresh happened to fetch.
     */
    public function touch(string $view, SavedViewRepository $views, ActorContext $actor): JsonResponse
    {
        $views->touchLastUsed($actor->workspaceId()->value, $view);

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
