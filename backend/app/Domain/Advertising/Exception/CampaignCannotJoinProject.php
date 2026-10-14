<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Exception;

use DomainException;

/**
 * A campaign was pointed at a project its external account is not bound to.
 *
 * Refused rather than permitted-and-audited, because allowing it would make assignment a second route to
 * the access that binding exists to control.
 */
final class CampaignCannotJoinProject extends DomainException
{
    public static function unbound(string $campaignId, string $projectId): self
    {
        return new self(sprintf(
            'Campaign %s cannot join project %s: the project is not bound to the campaign’s ad account.',
            $campaignId,
            $projectId,
        ));
    }
}
