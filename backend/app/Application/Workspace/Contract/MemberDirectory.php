<?php

declare(strict_types=1);

namespace App\Application\Workspace\Contract;

use App\Application\Workspace\DTO\InvitedMember;
use App\Domain\Access\RoleId;
use App\Domain\Identity\Email;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;

/**
 * Creating people, as opposed to reading or re-roling them.
 *
 * Separate from `MembershipRepository`, which is about the relationship between a user and a workspace. This one
 * creates the user — a different lifetime, a different table, and the only place in the product that generates a
 * password.
 */
interface MemberDirectory
{
    /** The account behind an address, where there already is one across the whole installation. */
    public function findByEmail(Email $email): ?UserId;

    /**
     * Add somebody to this workspace, creating their account if it does not exist.
     *
     * Returns the one-time password, which exists only in the returned object: it is never stored in plaintext,
     * never logged and never readable again.
     *
     * `$existing` is the account to join to this workspace rather than create. Resolved by the caller, which has
     * already decided whether joining somebody is the right act.
     */
    public function add(
        WorkspaceId $workspaceId,
        string $name,
        Email $email,
        RoleId $roleId,
        ?UserId $existing,
        /** A password the person chose themselves, so nobody else ever knows it. Null generates the one-time password. */
        ?string $chosenPassword = null,
        /** Who let them in, recorded on the membership. */
        ?UserId $invitedBy = null,
    ): InvitedMember;
}
