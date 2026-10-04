<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Workspace;

use App\Application\Shared\DTO\Pagination;
use App\Application\Workspace\Command\ChangeMemberRole;
use App\Application\Workspace\Command\ChangeMemberStatus;
use App\Application\Workspace\Command\RemoveMember;
use App\Application\Workspace\DTO\MemberView;
use App\Application\Workspace\Query\ListMembers;
use App\Domain\Identity\UserId;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Workspace\ChangeMemberRoleRequest;
use App\Interface\Http\Request\Workspace\ChangeMemberStatusRequest;
use App\Interface\Http\Resource\PaginatedCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** People in the active workspace, and their standing in it. */
final class MemberController extends Controller
{
    /** List workspace members. */
    public function index(Request $request, ListMembers $listMembers): JsonResponse
    {
        $page = $listMembers(
            $request->string('search')->value() ?: null,
            $request->string('role')->value() ?: null,
            $request->string('status')->value() ?: null,
            new Pagination(
                page: $request->integer('page', 1),
                perPage: $request->integer('per_page', Pagination::DEFAULT_PER_PAGE),
            ),
        );

        return PaginatedCollection::response($page, static fn (object $member): array => [
            'user_id' => $member instanceof MemberView ? $member->userId : '',
            'name' => $member instanceof MemberView ? $member->name : '',
            'email' => $member instanceof MemberView ? $member->email : '',
            'status' => $member instanceof MemberView ? $member->status : '',
            'role' => $member instanceof MemberView ? $member->roleSlug : '',
            'role_name' => $member instanceof MemberView ? $member->roleName : '',
            'job_title' => $member instanceof MemberView ? $member->jobTitle : null,
            'is_active' => $member instanceof MemberView && $member->isActive,
            'last_login_at' => $member instanceof MemberView ? $member->lastLoginAt : null,
            'joined_at' => $member instanceof MemberView ? $member->joinedAt : null,
            'project_count' => $member instanceof MemberView ? $member->projectCount : 0,
        ]);
    }

    /** Change a member's workspace role. */
    public function changeRole(
        string $user,
        ChangeMemberRoleRequest $request,
        ChangeMemberRole $changeRole,
    ): JsonResponse {
        $changeRole(new UserId($user), $request->string('role')->value());

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Suspend or reinstate a member. */
    public function changeStatus(
        string $user,
        ChangeMemberStatusRequest $request,
        ChangeMemberStatus $changeStatus,
    ): JsonResponse {
        $changeStatus(new UserId($user), $request->status());

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Remove a member from the workspace. Their account and audit trail remain. */
    public function destroy(string $user, RemoveMember $removeMember): JsonResponse
    {
        $removeMember(new UserId($user));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
