<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Access;

use App\Application\Access\Command\ManageAccessInvitation;
use App\Application\Access\Exception\AccessRequestRefused;
use App\Application\Identity\Command\ChangePassword;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The invitation: made by the people who answer requests, and accepted — by a stranger holding the link — on a public page.
 *
 * The public half answers the same for every token that is not a live invitation, so it cannot be used to ask which addresses
 * have been invited.
 */
final class AccessInvitationController extends Controller
{
    public function store(string $accessRequest, Request $request, ManageAccessInvitation $invitations): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', 'string', 'max:64'],
            'project_ids' => ['nullable', 'array', 'max:50'],
            'project_ids.*' => ['uuid'],
        ]);

        try {
            $made = $invitations->create($accessRequest, (string) $data['role'], array_values(array_map('strval', (array) ($data['project_ids'] ?? []))));
        } catch (AccessRequestRefused $refusal) {
            return AccessRequestController::refused($refusal);
        }

        return response()->json([
            'data' => [
                /* Shown once. Only its hash is kept, so it cannot be shown again. */
                'path' => '/invite/'.$made['token'],
                'hint' => $made['invitation']->tokenHint,
                'expires_at' => $made['invitation']->expiresAt->format(DATE_ATOM),
                'delivery' => ['email' => 'awaiting_credentials'],
            ],
        ], Response::HTTP_CREATED);
    }

    public function shared(string $accessRequest, ManageAccessInvitation $invitations): JsonResponse
    {
        try {
            $view = $invitations->markShared($accessRequest);
        } catch (AccessRequestRefused $refusal) {
            return AccessRequestController::refused($refusal);
        }

        return response()->json(['data' => AccessRequestController::row($view, null)]);
    }

    public function show(string $token, ManageAccessInvitation $invitations): JsonResponse
    {
        $open = $invitations->open($token);

        return $open === null
            ? response()->json(['data' => ['valid' => false]])
            : response()->json(['data' => ['valid' => true, ...$open]]);
    }

    public function accept(string $token, Request $request, ManageAccessInvitation $invitations): JsonResponse
    {
        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:'.ChangePassword::MINIMUM_LENGTH, 'max:200', 'confirmed'],
        ]);

        $accepted = $invitations->accept($token, isset($data['password']) ? (string) $data['password'] : null);

        return $accepted
            ? response()->json(['data' => ['accepted' => true]])
            : response()->json(['data' => ['accepted' => false]], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
