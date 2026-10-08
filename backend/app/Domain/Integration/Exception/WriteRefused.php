<?php

declare(strict_types=1);

namespace App\Domain\Integration\Exception;

use App\Domain\Integration\Provider;
use App\Domain\Integration\WriteOperation;
use App\Domain\Integration\WriteSupport;
use App\Domain\Operations\ApprovalKind;
use DomainException;

/**
 * A write this product will not attempt.
 *
 * Every case is a refusal **before** anything is sent, which is the distinction from {@see \App\Domain\Integration\WriteFailure}:
 * that is the provider saying no, this is the product declining to ask. Each carries a sentence an interface can
 * show, because "cannot carry this out" tells a reader nothing they can act on — and the next thing they will do
 * is go and change it in the platform, which is exactly right once they know why.
 *
 * `reasonCode` rather than `Exception::$code`, which is a non-readonly int on the base class.
 */
final class WriteRefused extends DomainException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function notApproved(): self
    {
        return new self(
            'This request has not been approved, so there is nothing to carry out.',
            'not_approved',
        );
    }

    public static function alreadySettled(): self
    {
        return new self(
            'This request has already been carried out or deliberately abandoned. Recording it twice would '
            .'overwrite who did it and when.',
            'already_settled',
        );
    }

    public static function proposalChanged(): self
    {
        return new self(
            'The proposal has changed since it was approved, so carrying it out would carry out something '
            .'nobody approved. Ask for approval of the amended proposal.',
            'proposal_changed',
        );
    }

    public static function proposalNamesNoCampaign(): self
    {
        return new self(
            'This request does not name a platform campaign, so this product cannot carry it out. Most '
            .'approvals do not — make the change in the platform and record it here.',
            'proposal_names_no_campaign',
        );
    }

    public static function providerNotDocumented(Provider $provider, WriteSupport $support): self
    {
        return new self(
            sprintf('%s: %s', $provider->label(), $support->because() ?? 'This write is not available.'),
            'provider_not_documented',
        );
    }

    public static function notConnected(Provider $provider): self
    {
        return new self(
            sprintf('%s is not connected to this workspace, so there are no credentials to act with.', $provider->label()),
            'not_connected',
        );
    }

    public static function capabilityMissing(Provider $provider, WriteOperation $operation): self
    {
        return new self(
            sprintf(
                '%s does not offer "%s" through its API as this product models it.',
                $provider->label(),
                $operation->label(),
            ),
            'capability_missing',
        );
    }

    public static function kindNotExecutable(ApprovalKind $kind): self
    {
        return new self(
            sprintf(
                'An approval of kind "%s" is not something this product can carry out. %s',
                $kind->label(),
                $kind->enactmentBlockedBecause() ?? '',
            ),
            'kind_not_executable',
        );
    }
}
