<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Identity;

use App\Application\Identity\Command\ChangePassword;
use App\Application\Identity\Command\LogIn;
use App\Application\Identity\Command\LogOut;
use App\Application\Identity\Query\GetAuthenticatedUser;
use App\Domain\Identity\Email;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Middleware\BindActorContext;
use App\Interface\Http\Request\Identity\ChangePasswordRequest;
use App\Interface\Http\Request\Identity\LogInRequest;
use App\Interface\Http\Resource\AuthenticatedUserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SessionController extends Controller
{
    /**
     * Sign in.
     *
     * Establishes a first-party session. Rate limited per IP and per e-mail;
     * failures are recorded in the audit log.
     */
    public function store(LogInRequest $request, LogIn $logIn): JsonResponse
    {
        $user = $logIn(
            new Email($request->string('email')->value()),
            $request->string('password')->value(),
            $request->remember(),
        );

        // Remember the workspace the session landed in, so a later request does
        // not silently fall back to a different one.
        if ($user->activeWorkspace !== null && $request->hasSession()) {
            $request->session()->put(BindActorContext::SESSION_KEY, $user->activeWorkspace->id);
        }

        return AuthenticatedUserResource::make($user)->response();
    }

    /** The current session's user, workspaces and effective permissions. */
    public function show(Request $request, GetAuthenticatedUser $getUser): JsonResponse
    {
        $workspaceId = $request->hasSession()
            ? $request->session()->get(BindActorContext::SESSION_KEY)
            : null;

        $user = $getUser(
            new UserId((string) $request->user()?->getAuthIdentifier()),
            is_string($workspaceId) ? new WorkspaceId($workspaceId) : null,
        );

        return AuthenticatedUserResource::make($user)->response();
    }

    /**
     * Change your own password.
     *
     * No permission check and no user parameter: a password belongs to the person rather than to a role, and
     * there is no shape in which this reaches somebody else's account. The current password is required because a
     * session can be left open on a machine that is not yours.
     *
     * Answered with no body. The session survives — signing somebody out of the screen they are looking at reads
     * as a failure — and every **other** session using the old password is ended.
     */
    public function changePassword(ChangePasswordRequest $request, ChangePassword $change): JsonResponse
    {
        $change(
            $request->string('current_password')->value(),
            $request->string('password')->value(),
        );

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }

    /** Sign out and invalidate the session. */
    public function destroy(Request $request, LogOut $logOut): JsonResponse
    {
        $workspaceId = $request->hasSession()
            ? $request->session()->get(BindActorContext::SESSION_KEY)
            : null;

        $logOut(is_string($workspaceId) ? new WorkspaceId($workspaceId) : null);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
