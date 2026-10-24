<?php

declare(strict_types=1);

namespace App\Application\Workspace\Exception;

use App\Domain\Shared\Exception\DomainException;

final class MemberNotAdded extends DomainException
{
    public function errorCode(): string
    {
        return 'member_not_added';
    }

    public static function alreadyAMember(): self
    {
        return new self('That address already belongs to a member of this workspace. Change their role or status instead.');
    }

    /**
     * An owner is not handed out from an add form.
     *
     * An owner can do everything, including removing other owners, so creating one is a decision for somebody
     * already in that position. `ChangeMemberRole` is where it happens, with the last-owner protection around it.
     */
    public static function ownerIsNotHandedOut(): self
    {
        return new self('Add them in another role first, then change it. Making somebody an owner is a separate decision, and the owner role is the one that can remove other owners.');
    }

    public static function seatDoesNotFitRole(): self
    {
        return new self('A client viewer takes a client or guest seat; everybody else takes a full or limited seat.');
    }
}
