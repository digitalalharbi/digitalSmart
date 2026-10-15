<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\SurfaceView;

use App\Application\SurfaceView\Command\DeleteSurfaceView;
use App\Application\SurfaceView\Command\SaveSurfaceView;
use App\Application\SurfaceView\DTO\SurfaceViewView;
use App\Application\SurfaceView\Query\ListSurfaceViews;
use App\Domain\Dashboard\Visibility;
use App\Domain\SurfaceView\SurfaceKind;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/** Saved filter sets for the agency surfaces: private, a project's team, or the whole workspace. */
final class SurfaceViewController extends Controller
{
    public function index(string $surface, ListSurfaceViews $list): JsonResponse
    {
        $kind = SurfaceKind::tryFrom($surface);

        if ($kind === null) {
            return $this->unknown();
        }

        return response()->json(['data' => array_map($this->present(...), $list($kind))]);
    }

    public function store(Request $request, string $surface, SaveSurfaceView $save): JsonResponse
    {
        $kind = SurfaceKind::tryFrom($surface);

        if ($kind === null) {
            return $this->unknown();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'visibility' => ['sometimes', 'in:'.implode(',', Visibility::values())],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'filters' => ['sometimes', 'array'],
        ]);

        $name = $validated['name'];
        $projectId = $validated['project_id'] ?? null;
        $filters = $validated['filters'] ?? [];

        $view = $save(
            $kind,
            trim(is_string($name) ? $name : ''),
            Visibility::tryFrom(is_string($validated['visibility'] ?? null) ? $validated['visibility'] : '') ?? Visibility::Private,
            is_string($projectId) ? $projectId : null,
            is_array($filters) ? $filters : [],
        );

        return response()->json(['data' => $this->present($view)], Response::HTTP_CREATED);
    }

    public function destroy(string $view, DeleteSurfaceView $delete): JsonResponse
    {
        $delete($view);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function unknown(): JsonResponse
    {
        return response()->json([
            'message' => 'That surface has no saved views.',
            'code' => 'unknown_surface',
            'allowed' => SurfaceKind::values(),
        ], Response::HTTP_NOT_FOUND);
    }

    /** @return array<string, mixed> */
    private function present(SurfaceViewView $v): array
    {
        return [
            'id' => $v->id,
            'surface' => $v->surface->value,
            'name' => $v->name,
            'visibility' => $v->visibility->value,
            'visibility_label' => $v->visibility->label(),
            'project_id' => $v->projectId,
            'project_name' => $v->projectName,
            'filters' => $v->filters === [] ? new stdClass : $v->filters,
            'author_name' => $v->authorName,
            'is_mine' => $v->isMine,
        ];
    }
}
