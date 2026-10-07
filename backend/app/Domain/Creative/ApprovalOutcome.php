<?php

declare(strict_types=1);

namespace App\Domain\Creative;

/**
 * What a reviewer decided about one version.
 *
 * Attached to a version and never to a request, which is the single rule that makes an approval trail
 * worth having: a client approved a file, and the next file is unapproved until somebody says otherwise.
 */
enum ApprovalOutcome: string
{
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';

    /** Whether this decision must be accompanied by a reason. */
    public function needsReason(): bool
    {
        /*
         * Only a rejection. "Approved" needs no justification; "changes requested" without one leaves
         * the next version a guess, and the note is the only record of what the reviewer objected to.
         */
        return $this === self::ChangesRequested;
    }

    public function state(): CreativeState
    {
        return match ($this) {
            self::Approved => CreativeState::Approved,
            self::ChangesRequested => CreativeState::ChangesRequested,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::ChangesRequested => 'Changes requested',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
