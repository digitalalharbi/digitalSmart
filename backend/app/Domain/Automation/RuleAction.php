<?php

declare(strict_types=1);

namespace App\Domain\Automation;

/**
 * What a rule does when it holds. Never more than ask: the strongest action is a proposal a person decides.
 */
enum RuleAction: string
{
    /** A card on Today. */
    case Alert = 'alert';
    /** A card, and a proposal to pause the campaign, waiting for approval. */
    case ProposePause = 'propose_pause';
    /** A card, and a proposal to lower the daily budget by a stated percentage, waiting for approval. */
    case ProposeBudgetCut = 'propose_budget_cut';
    /** A card, and a task opened on the board without a person confirming the rule's run. */
    case Task = 'task';

    /** The least trust a rule must be given before it may do this. */
    public function minimumTrust(): TrustLevel
    {
        return match ($this) {
            /* At level 0 the alert is evaluated and kept, and shown nowhere — the rule is watching. */
            self::Alert => TrustLevel::Watch,
            self::ProposePause, self::ProposeBudgetCut => TrustLevel::Propose,
            self::Task => TrustLevel::ActInternally,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }
}
