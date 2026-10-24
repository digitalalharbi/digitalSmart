<?php

declare(strict_types=1);

namespace App\Application\Workspace\Command;

use App\Application\Saas\EntitlementGuard;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Workspace\Contract\MemberDirectory;
use App\Application\Workspace\Contract\MembershipRepository;
use App\Application\Workspace\DTO\InvitedMember;
use App\Application\Workspace\Exception\MemberNotAdded;
use App\Domain\Access\Permission;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\Email;
use App\Domain\Saas\SeatType;

/**
 * Add a colleague to the workspace.
 *
 * **Nothing could do this.** `Permission::UsersInvite` was granted to owners and administrators and checked
 * nowhere, `AuditAction::WorkspaceMemberInvited` was declared with nothing recording it, and
 * `MembershipStatus::Invited` existed with nothing ever setting it. Three pieces of vocabulary describing an
 * operation the product could not perform: roles could be changed, memberships suspended and people removed,
 * but the workspace was closed to anybody new.
 *
 * **No email, so no invitation link — and the product says so rather than pretending.** This repository has no
 * sender identity, which is the same block that defers notification digests. What is built instead is honest
 * about its own weakness: a one-time password, returned once for the administrator to read out, and made
 * worthless the moment it is used. `must_change_password` stops every other request until the member chooses
 * their own, so "an administrator knows your password" is true for one sign-in rather than indefinitely.
 *
 * **`MembershipStatus::Invited` is deliberately not used.** A member in that state cannot sign in at all —
 * `grantsAccess()` is false and they resolve to no workspace — so without an activation route it would be an
 * account nobody could ever open. The status stays reserved for the email flow that is blocked.
 *
 * **The owner role cannot be handed out here.** An owner can do everything including removing other owners, and
 * creating one is a decision for somebody already in that position — `ChangeMemberRole` is where it happens,
 * deliberately, with the last-owner protection around it.
 */
final readonly class InviteMember
{
    public function __construct(
        private MemberDirectory $directory,
        private MembershipRepository $memberships,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private EntitlementGuard $entitlements,
    ) {}

    public function __invoke(string $name, Email $email, string $roleSlug): InvitedMember
    {
        $this->guard->ensure(Permission::UsersInvite);

        $workspaceId = $this->actor->workspaceId();

        if (SystemRole::tryFrom($roleSlug) === SystemRole::Owner) {
            throw MemberNotAdded::ownerIsNotHandedOut();
        }

        $roleId = $this->memberships->findRoleIdBySlug($workspaceId, $roleSlug)
            ?? throw ResourceNotFound::of('Role');

        /*
         * One account per person across the product, so somebody who already works with another agency on this
         * installation is added to this workspace rather than duplicated. Refused rather than silently joined,
         * because adding somebody to a workspace they can already see is a different act from creating them and
         * the administrator should know which they are doing.
         */
        $existing = $this->directory->findByEmail($email);

        if ($existing !== null && $this->memberships->find($workspaceId, $existing) !== null) {
            throw MemberNotAdded::alreadyAMember();
        }

        $invited = $this->entitlements->claim(
            SeatType::defaultFor($roleSlug)->countsAgainst(),
            fn () => $this->directory->add($workspaceId, $name, $email, $roleId, $existing, null, $this->actor->userId()),
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $this->actor->userId(),
            action: AuditAction::WorkspaceMemberInvited,
            auditableType: 'user',
            auditableId: $invited->userId,
            summary: sprintf('Added %s to the workspace as %s', $invited->email, $roleSlug),
            changes: [
                'role' => $roleSlug,
                /* Whether this was a new account or an existing person joining a second workspace. */
                'existing_account' => $existing !== null,
                /* The password is never here. See `InvitedMember`. */
            ],
        ));

        return $invited;
    }
}
