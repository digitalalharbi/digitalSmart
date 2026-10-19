<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * How far a rule goes when it holds — shown on every rule, chosen by a person.
 *
 * Monitor: it records and shows; nothing is proposed. Recommend (the default): it raises a proposal a person with the
 * right to decide approves or rejects. Auto: it would carry the change out unasked — available only when the owner
 * has explicitly authorised auto-execution for the workspace **and** the platform's write is documented. Neither is
 * true today, so saving a rule in Auto is refused with the reason; nothing silently downgrades it.
 */
enum RuleMode: string
{
    case Monitor = 'monitor';
    case Recommend = 'recommend';
    case Auto = 'auto';

    /** The trust level this mode stands for on the existing ladder. */
    public function trust(RuleAction $action): TrustLevel
    {
        return match ($this) {
            self::Monitor => TrustLevel::Alert,
            self::Recommend => $action === RuleAction::Task ? TrustLevel::ActInternally : TrustLevel::Propose,
            self::Auto => TrustLevel::Autonomous,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $m): string => $m->value, self::cases());
    }
}
