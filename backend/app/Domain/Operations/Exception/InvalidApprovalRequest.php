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
}
