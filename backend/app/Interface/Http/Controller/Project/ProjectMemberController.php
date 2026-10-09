<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Project;

use App\Application\Project\Command\AssignProjectMember;
use App\Application\Project\Command\RemoveProjectMember;
use App\Application\Project\Query\ListAssignableToProject;
use App\Application\Project\Query\ListProjectMembers;
use App\Domain\Identity\UserId;
use App\Domain\Project\ProjectId;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Project\AssignMemberRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ProjectMemberController extends Controller
{
    /** People assigned to a project. */
    public function index(string $project, ListProjectMembers $listMembers): JsonResponse
    {
        return new JsonResponse(['data' => $listMembers(new ProjectId($project))]);
    }

    /**
     * Who could be given access to this project.
     *
     * Served rather than assembled from the member directory: this list is active members **not already on the
     * project**, which is the only set worth offering, and it carries no email addresses.
     */
    public function assignable(string $project, ListAssignableToProject $list): JsonResponse
    {
        return new JsonResponse(['data' => $list(new ProjectId($project))]);
    }

    /** Assign someone to a project. This is what grants them visibility of it. */
    public function store(
        string $project,
        AssignMemberRequest $request,
        AssignProjectMember $assign,
    ): JsonResponse {
        $assign(
            new ProjectId($project),
            new UserId($request->string('user_id')->value()),
            $request->string('role')->value(),
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Remove someone from a project, revoking their access to it. */
    public function destroy(string $project, string $user, RemoveProjectMember $remove): JsonResponse
    {
        $remove(new ProjectId($project), new UserId($user));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
