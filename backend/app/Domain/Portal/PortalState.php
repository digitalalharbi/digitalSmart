<?php

declare(strict_types=1);

namespace App\Domain\Portal;

/**
 * Whether a client's portal is readable.
 *
 * Three states, and the third is the one that earns its keep.
 *
 * A portal is **suspended**, not deleted, when an engagement pauses or a dispute starts. Deleting the row would
 * discard which modules had been exposed and who exposed them, which is exactly the record somebody asks for during
 * a dispute — and it would make resuming an act of reconstruction from memory. Suspension takes effect on the next
 * request, not at the end of a billing period: somebody suspends a portal because something should stop being
 * readable now.
 *
 * `NotConfigured` is distinct from `Suspended` for the same reason the reporting module distinguishes "no
 * comparison requested" from "a comparison that cannot be made": one is a decision nobody has taken yet, and the
 * other is a decision somebody took.
 */
enum PortalState: string
{
    /** Nobody has set this client up with a portal. Not a refusal — an absence. */
    case NotConfigured = 'not_configured';

    case Active = 'active';

    /** Switched off deliberately, with a reason, keeping the record of what had been exposed. */
    case Suspended = 'suspended';

    public function allowsAccess(): bool
    {
        return $this === self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::NotConfigured => 'Not set up',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
        };
    }

    /**
     * What a client is told when their portal will not open.
     *
     * Never "not found", and never the suspension reason. The reason is the agency's own note — "payment overdue",
     * "contract under review" — and a client reading it on a sign-in screen is a commercial conversation happening
     * in the worst possible place. They are told to make contact, which is the outcome either way.
     */
    public function visitorMessage(): ?string
    {
        return match ($this) {
            self::Active => null,
            self::NotConfigured => 'Your portal has not been set up yet. Your account manager can switch it on.',
            self::Suspended => 'Your portal is currently switched off. Please contact your account manager.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
