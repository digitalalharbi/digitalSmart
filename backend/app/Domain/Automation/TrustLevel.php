<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * How far a rule is trusted to go on its own.
 *
 * Set per rule by a person and never raised by the system: a rule cannot promote itself, an agent has no tool that
 * changes it, and saving a rule whose action needs more trust than it was given is refused rather than quietly
 * raised. Level 4 — changing a platform without a person's approval — exists so it can be named and refused: it needs
 * a workspace automation policy and a documented provider write, and neither is in place.
 */
enum TrustLevel: int
{
    /** Evaluated and recorded; nothing shown. */
    case Watch = 0;
    /** A card on Today. */
    case Alert = 1;
    /** Cards and proposals that wait for a person's approval. The default for anything touching a platform. */
    case Propose = 2;
    /** As 2, and internal work — tasks — opened without asking. Platform changes still wait for approval. */
    case ActInternally = 3;
    /** Platform changes without approval. Not available. */
    case Autonomous = 4;

    public function allows(RuleAction $action): bool
    {
        return $this !== self::Autonomous && $this->value >= $action->minimumTrust()->value;
    }

    public function isAvailable(): bool
    {
        return $this !== self::Autonomous;
    }
}
