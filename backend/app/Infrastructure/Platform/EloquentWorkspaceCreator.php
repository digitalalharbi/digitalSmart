<?php

declare(strict_types=1);

namespace App\Infrastructure\Platform;

use App\Application\Platform\Contract\WorkspaceCreator;
use App\Application\Platform\DTO\CreatedWorkspace;
use App\Application\Platform\DTO\NewWorkspace;
use App\Application\Workspace\Contract\MemberDirectory;
use App\Domain\Access\RoleId;
use App\Domain\Access\SystemRole;
use App\Domain\Identity\Email;
use App\Domain\Workspace\WorkspaceId;
use App\Infrastructure\Workspace\WorkspaceProvisioner;
use Illuminate\Support\Facades\DB;

/**
 * The workspace, its roles, its plan and state, and its owner, in one transaction. The owner is added through the same member
 * directory every other addition goes through, so an existing account joins and a new one gets a one-time password that must be
 * replaced on first sign-in.
 */
final readonly class EloquentWorkspaceCreator implements WorkspaceCreator
{
    public function __construct(
        private WorkspaceProvisioner $provisioner,
        private MemberDirectory $directory,
    ) {}

    public function create(NewWorkspace $workspace): CreatedWorkspace
    {
        return DB::transaction(function () use ($workspace): CreatedWorkspace {
            $model = $this->provisioner->provisionWithoutOwner(
                name: $workspace->name,
                slug: $workspace->slug,
                defaultCurrency: $workspace->currency,
                defaultLocale: $workspace->locale,
                timezone: $workspace->timezone,
                type: $workspace->type,
                planCode: $workspace->planCode,
            );
            $id = (string) $model->getKey();

            DB::table('workspace_subscriptions')->where('workspace_id', $id)->update([
                'status' => $workspace->status->value,
                'trial_ends_at' => $workspace->trialEndsAt?->format(DATE_ATOM),
                'assigned_by' => 'platform',
            ]);

            $email = new Email($workspace->ownerEmail);
            $ownerRole = (string) DB::table('roles')->where('workspace_id', $id)->where('slug', SystemRole::Owner->value)->value('id');
            $member = $this->directory->add(
                new WorkspaceId($id),
                $workspace->ownerName,
                $email,
                new RoleId($ownerRole),
                $this->directory->findByEmail($email),
            );

            return new CreatedWorkspace(
                workspaceId: $id,
                ownerUserId: $member->userId,
                ownerEmail: $member->email,
                oneTimePassword: $member->oneTimePassword === '' ? null : $member->oneTimePassword,
            );
        });
    }
}
