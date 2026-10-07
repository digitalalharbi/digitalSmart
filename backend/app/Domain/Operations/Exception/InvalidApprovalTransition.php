<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exception;

use App\Domain\Operations\ApprovalState;
use App\Domain\Operations\EnactmentState;
use RuntimeException;

/** Something an approval trail must not be asked to record. */
final class InvalidApprovalTransition extends RuntimeException
{
    /*
     * `reasonCode` rather than `code`: PHP's own `Exception::$code` is an int and not readonly, so a property
     * of that name cannot be redeclared here. The name is slightly worse and the alternative does not compile.
     */
    private function __construct(
        string $message,
        public readonly string $reasonCode,
        public readonly ApprovalState $from,
    ) {
        parent::__construct($message);
    }

    /**
     * Deciding, amending or withdrawing something already settled.
     *
     * Refused rather than recorded. Re-deciding a settled request produces a trail in which the same request
     * was both declined and approved, and nothing in it says which one the client was told about.
     */
    public static function alreadySettled(ApprovalState $from, ApprovalState $to): self
    {
        return new self(
            sprintf(
                '%s. A settled request cannot be changed — raise a new one, naming this as the request it '
                .'replaces, so both decisions stay on the record.',
                $from->label(),
            ),
            'already_settled',
            $from,
        );
    }

    public static function reasonRequired(ApprovalState $from, ApprovalState $to): self
    {
        return new self(
            $to === ApprovalState::Declined
                ? 'Say why it is being declined. The reason is what the next version of this proposal is built on.'
                : 'Say why it is being withdrawn, so the record is not just a request that vanished.',
            'reason_required',
            $from,
        );
    }

    /** Marking a change carried out when nobody approved it. */
    public static function notApproved(ApprovalState $from): self
    {
        return new self(
            sprintf(
                'Only an approved change can be marked carried out. This one is: %s.',
                $from->label(),
            ),
            'not_approved',
            $from,
        );
    }

    /** Marking a change carried out twice, or abandoning one already done. */
    public static function enactmentSettled(ApprovalState $from, EnactmentState $enactment): self
    {
        return new self(
            sprintf('This change is already recorded as %s.', mb_strtolower($enactment->label())),
            'enactment_settled',
            $from,
        );
    }
}
