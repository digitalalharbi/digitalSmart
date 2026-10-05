<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Integration;

use App\Application\Integration\Command\BindAccountToProject;
use App\Application\Integration\Command\SetBindingSync;
use App\Application\Integration\Command\UnbindAccountFromProject;
use App\Application\Integration\DTO\BindingView;
use App\Application\Integration\Query\ListProjectBindings;
use App\Domain\Integration\ExternalAccountId;
use App\Domain\Project\ProjectId;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Request\Integration\BindAccountRequest;
use App\Interface\Http\Request\Integration\SetBindingSyncRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which external ad accounts a project may read.
 *
 * Listing requires only visibility of the project; granting and revoking
 * require workspace administration, because this is the access boundary
 * between clients.
 */
final class ProjectBindingController extends Controller
{
    public function index(string $project, ListProjectBindings $listBindings): JsonResponse
    {
        $bindings = $listBindings(new ProjectId($project));

        return new JsonResponse([
            'data' => array_map(static fn (BindingView $binding): array => [
                'id' => $binding->id,
                'project_id' => $binding->projectId,
                'external_account' => [
                    'id' => $binding->externalAccountId,
                    'name' => $binding->externalAccountName,
                    'provider' => $binding->provider,
                    'provider_label' => $binding->providerLabel,
                ],
                'sync_enabled' => $binding->syncEnabled,
                'bound_at' => $binding->boundAt,
                'last_synced_at' => $binding->lastSyncedAt,
            ], $bindings),
        ]);
    }

    /** Grant this project access to an external account. */
    public function store(string $project, BindAccountRequest $request, BindAccountToProject $bind): JsonResponse
    {
        $binding = $bind(
            new ProjectId($project),
            new ExternalAccountId($request->string('external_account_id')->value()),
        );

        return new JsonResponse([
            'data' => [
                'id' => $binding->id->value,
                'project_id' => $binding->projectId->value,
                'external_account_id' => $binding->externalAccountId->value,
                'sync_enabled' => $binding->syncEnabled(),
            ],
        ], Response::HTTP_CREATED);
    }

    /** Pause or resume syncing without revoking access. */
    public function update(
        string $project,
        string $account,
        SetBindingSyncRequest $request,
        SetBindingSync $setSync,
    ): JsonResponse {
        $setSync(new ProjectId($project), new ExternalAccountId($account), $request->boolean('sync_enabled'));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /** Revoke this project's access to the account. */
    public function destroy(string $project, string $account, UnbindAccountFromProject $unbind): JsonResponse
    {
        $unbind(new ProjectId($project), new ExternalAccountId($account));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
