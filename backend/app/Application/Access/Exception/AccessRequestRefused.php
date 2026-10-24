<?php

declare(strict_types=1);

namespace App\Application\Access\Exception;

use App\Domain\Access\AccessRequestStatus;
use DomainException;

final class AccessRequestRefused extends DomainException
{
    public function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function cannotMove(AccessRequestStatus $from, AccessRequestStatus $to): self
    {
        return new self(
            sprintf('A request that is %s cannot become %s.', $from->value, $to->value),
            'access_request_cannot_move',
        );
    }

    public static function notAnAccountRequest(): self
    {
        return new self('Only a request for an account can be approved and invited.', 'access_request_not_account');
    }

    public static function notApproved(): self
    {
        return new self('Approve the request before inviting the person.', 'access_request_not_approved');
    }

    public static function reasonRequired(): self
    {
        return new self('Say why the request is being rejected. The reason stays internal.', 'access_request_reason_required');
    }

    public static function ownerCannotAnswer(): self
    {
        return new self('That person cannot answer requests for access, so the request cannot be given to them.', 'access_request_owner_ineligible');
    }

    public static function roleNotOffered(): self
    {
        return new self('That role cannot be given through an invitation.', 'access_invitation_role');
    }

    public static function alreadyAMember(): self
    {
        return new self('That address already belongs to a member of this workspace.', 'access_invitation_member');
    }

    public static function invitationUnusable(): self
    {
        return new self('This invitation is not valid.', 'access_invitation_invalid');
    }

    public static function closed(): self
    {
        return new self('This request is closed.', 'access_request_closed');
    }
}
