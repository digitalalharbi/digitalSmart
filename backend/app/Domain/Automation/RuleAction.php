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
    /** A card, and a proposal to resume a paused campaign, waiting for approval. */
    case ProposeResume = 'propose_resume';
    /** A card, and a proposal to raise the daily budget by a stated percentage, waiting for approval. */
    case ProposeBudgetIncrease = 'propose_budget_increase';
    /** Bid changes: shown so they can be named, and refused — no platform's bid write is verified here. */
    case ProposeBidChange = 'propose_bid_change';

    /** The least trust a rule must be given before it may do this. */
    public function minimumTrust(): TrustLevel
    {
        return match ($this) {
            /* At level 0 the alert is evaluated and kept, and shown nowhere — the rule is watching. */
            self::Alert => TrustLevel::Watch,
            self::ProposePause, self::ProposeBudgetCut, self::ProposeResume, self::ProposeBudgetIncrease,
            self::ProposeBidChange => TrustLevel::Propose,
            self::Task => TrustLevel::ActInternally,
        };
    }

    /** Whether the action needs a percentage. */
    public function needsPct(): bool
    {
        return in_array($this, [self::ProposeBudgetCut, self::ProposeBudgetIncrease, self::ProposeBidChange], true);
    }

    /** The platform write this action would ask for, or null for an action that writes nothing to a platform. */
    public function writeOperation(): ?\App\Domain\Integration\WriteOperation
    {
        return match ($this) {
            self::ProposePause => \App\Domain\Integration\WriteOperation::PauseCampaign,
            self::ProposeResume => \App\Domain\Integration\WriteOperation::ResumeCampaign,
            self::ProposeBudgetCut, self::ProposeBudgetIncrease => \App\Domain\Integration\WriteOperation::SetCampaignDailyBudget,
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }
}
