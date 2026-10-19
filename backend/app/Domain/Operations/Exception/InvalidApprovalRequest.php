<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exception;

use RuntimeException;

/** A request nobody could act on. */
final class InvalidApprovalRequest extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function rationaleRequired(): self
    {
        return new self(
            'Say why this change is wanted. It is the only record of the reason, and the first thing anybody '
            .'asks about it afterwards.',
            'rationale_required',
        );
    }

    /**
     * An empty proposal.
     *
     * Refused rather than stored. "Approve this" with nothing in it is a signature on a blank page, and the
     * decision would be unquotable the moment anybody disagreed about what was agreed.
     */
    public static function proposalRequired(): self
    {
        return new self(
            'Say what is being proposed. A decider has to be able to read what they are agreeing to.',
            'proposal_required',
        );
    }

    public static function notAStatusChange(): self
    {
        return new self('Only pausing or resuming a campaign can be asked for here.', 'not_a_status_change');
    }

    /** The platform's own documentation was not read for this write, so it is not offered — see Provider::writeSupport(). */
    public static function providerNotDocumented(string $provider): self
    {
        return new self(
            sprintf('This product cannot yet pause or resume a campaign on %s, so there is nothing to approve.', $provider),
            'provider_not_documented',
        );
    }

    /** A change the platform's documentation does not describe at this level — in the platform's words where it said so. */
    public static function notAtThisLevel(string $because): self
    {
        return new self($because, 'provider_not_documented');
    }

    /** An ad group or ad that is not in a state the change makes sense for. */
    public static function wrongEntityState(string $noun, bool $pausing): self
    {
        return new self(
            $pausing
                ? sprintf('That %s is not running, so there is nothing to pause.', $noun)
                : sprintf('That %s is not paused, so there is nothing to resume.', $noun),
            'wrong_state',
        );
    }

    public static function wrongState(bool $pausing): self
    {
        return new self(
            $pausing
                ? 'That campaign is not running, so there is nothing to pause.'
                : 'That campaign is not paused, so there is nothing to resume.',
            'wrong_state',
        );
    }
}
