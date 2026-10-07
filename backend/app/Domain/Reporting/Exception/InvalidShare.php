<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exception;

use RuntimeException;

/** A link this product will not create. */
final class InvalidShare extends RuntimeException
{
    private function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    /**
     * Sharing a report written for internal eyes.
     *
     * Refused rather than sanitised, and the message says why: a report written with internal commentary in mind
     * has internal commentary throughout — in its section titles, in which metrics were chosen, in what was left
     * out. Stripping one field would produce a document that looks client-safe and was not written to be.
     */
    public static function reportIsInternal(): self
    {
        return new self(
            'This report is marked internal, so it cannot be shared. Mark it safe to send — which means reading '
            .'it as a client would, not just removing the notes — or copy it into a client-safe report.',
            'report_is_internal',
        );
    }

    public static function expiryOutOfRange(int $maxDays): self
    {
        return new self(
            sprintf(
                'A link lasts between a day and %d days. One with no end outlives the engagement, the account '
                .'manager and sometimes the relationship.',
                $maxDays,
            ),
            'expiry_out_of_range',
        );
    }

    public static function revocationNeedsAReason(): self
    {
        return new self(
            'Say why the link is being turned off. It is the only record of what went wrong with it.',
            'revocation_needs_a_reason',
        );
    }
}
