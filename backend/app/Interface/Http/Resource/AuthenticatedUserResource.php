<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource;

use App\Application\Identity\DTO\AuthenticatedUser;
use App\Application\Identity\DTO\WorkspaceSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AuthenticatedUser $resource
 */
final class AuthenticatedUserResource extends JsonResource
{
    public function __construct(AuthenticatedUser $resource)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'timezone' => $user->timezone,
            'job_title' => $user->jobTitle,
            'avatar_url' => $user->avatarUrl,
            'workspaces' => array_map($this->workspace(...), $user->workspaces),
            'active_workspace' => $user->activeWorkspace === null
                ? null
                : $this->workspace($user->activeWorkspace),
            // The SPA uses these to hide controls; the server enforces them.
            'permissions' => $user->permissions,
            /*
             * The one flag the interface must act on rather than merely display: until it is false the server
             * refuses everything else, so a client that ignored it would show a wall of refusals instead of the
             * single thing this person can do.
             */
            'must_choose_password' => $user->mustChoosePassword,
        ];
    }

    /** @return array<string, string> */
    private function workspace(WorkspaceSummary $workspace): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'type' => $workspace->type,
            'role' => $workspace->roleSlug,
            'role_name' => $workspace->roleName,
            'default_currency' => $workspace->defaultCurrency,
            'default_locale' => $workspace->defaultLocale,
            'timezone' => $workspace->timezone,
        ];
    }
}
