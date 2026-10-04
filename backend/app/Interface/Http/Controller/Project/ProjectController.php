<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Project;

use App\Application\Project\Command\ChangeProjectStatus;
use App\Application\Project\Command\CreateProject;
use App\Application\Project\Command\DeleteProject;
use App\Application\Project\Command\UpdateProject;
use App\Application\Project\DTO\ProjectFilters;
use App\Application\Project\Query\GetProject;
use App\Application\Project\Query\ListProjects;
use App\Application\Shared\DTO\Pagination;
use App\Domain\Project\ProjectId;
use App\Domain\Project\ProjectStatus;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Project\ChangeProjectStatusRequest;
use App\Interface\Http\Request\Project\StoreProjectRequest;
use App\Interface\Http\Request\Project\UpdateProjectRequest;
use App\Interface\Http\Resource\PaginatedCollection;
use App\Interface\Http\Resource\ProjectResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Projects in the active workspace.
 *
 * Visibility is decided inside the use cases: a role without
 * `projects.view_all` sees only the projects it is assigned to.
 */
final class ProjectController extends Controller
{
    /** List projects the caller may see. */
    public function index(Request $request, ListProjects $listProjects): JsonResponse
    {
        $page = $listProjects(
            new ProjectFilters(
                search: $request->string('search')->value() ?: null,
                statuses: $this->statuses($request),
                clientId: $request->string('client_id')->value() ?: null,
                isDemo: $request->has('is_demo') ? $request->boolean('is_demo') : null,
                sort: $request->string('sort', 'name')->value(),
                direction: $request->string('direction', 'asc')->value(),
            ),
            new Pagination(
                page: $request->integer('page', 1),
                perPage: $request->integer('per_page', Pagination::DEFAULT_PER_PAGE),
            ),
        );

        return PaginatedCollection::response(
            $page,
            static fn (object $project): array => ProjectResource::make($project)->resolve(),
        );
    }

    /** A single project. */
    public function show(string $project, GetProject $getProject): JsonResponse
    {
        return ProjectResource::make($getProject(new ProjectId($project)))->response();
    }

    /** Create a project for a client in this workspace. It starts in planning. */
    public function store(StoreProjectRequest $request, CreateProject $createProject): JsonResponse
    {
        return ProjectResource::make($createProject($request->toInput()))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /** Update a project's details and schedule. */
    public function update(
        string $project,
        UpdateProjectRequest $request,
        GetProject $getProject,
        UpdateProject $updateProject,
    ): JsonResponse {
        // The client cannot be changed, so it is read from the stored project
        // rather than accepted from the request.
        $current = $getProject(new ProjectId($project));

        return ProjectResource::make(
            $updateProject(new ProjectId($project), $request->toInput($current->clientId)),
        )->response();
    }

    /** Move a project through its lifecycle. Illegal transitions are rejected. */
    public function changeStatus(
        string $project,
        ChangeProjectStatusRequest $request,
        ChangeProjectStatus $changeStatus,
    ): JsonResponse {
        return ProjectResource::make($changeStatus(new ProjectId($project), $request->status()))->response();
    }

    /** Soft-delete a project, retaining its history. */
    public function destroy(string $project, DeleteProject $deleteProject): JsonResponse
    {
        $deleteProject(new ProjectId($project));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** @return list<ProjectStatus> */
    private function statuses(Request $request): array
    {
        $raw = $request->input('status', []);
        $values = is_array($raw) ? $raw : [$raw];

        $statuses = [];

        foreach ($values as $value) {
            if (is_string($value)) {
                $status = ProjectStatus::tryFrom($value);

                if ($status instanceof ProjectStatus) {
                    $statuses[] = $status;
                }
            }
        }

        return $statuses;
    }
}
