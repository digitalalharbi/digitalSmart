<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Identity;

use App\Application\Identity\Command\SwitchWorkspace;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Middleware\BindActorContext;
use App\Interface\Http\Resource\AuthenticatedUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkspaceSwitchController extends Controller
{
    /**
     * Switch the session's active workspace.
     *
     * Membership is re-verified; a workspace the user does not belong to is
     * reported as not found.
     */
    public function __invoke(Request $request, string $workspace, SwitchWorkspace $switch): JsonResponse
    {
        $user = $switch(
            new UserId((string) $request->user()?->getAuthIdentifier()),
            new WorkspaceId($workspace),
        );

        if ($request->hasSession()) {
            $request->session()->put(BindActorContext::SESSION_KEY, $workspace);
        }

        return AuthenticatedUserResource::make($user)->response();
    }
}
