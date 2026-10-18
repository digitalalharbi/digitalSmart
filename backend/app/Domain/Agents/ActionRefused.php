<?php

declare(strict_types=1);

namespace App\Domain\Agents;

use RuntimeException;

/**
 * A refusal the code makes on its own — a hard limit, a duplicate, a cooldown — whoever is asking.
 *
 * No agent, routine or outside client gets past one of these by phrasing a request differently: the limits live
 * here, not in a prompt.
 */
final class ActionRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public static function notDaily(): self
    {
        return new self('Only a campaign with its own daily budget can have that budget proposed.', 'budget_not_daily');
    }

    public static function notDocumented(string $provider): self
    {
        return new self(sprintf('%s’s budget change has not been read in its own documentation, so no change is proposed.', $provider), 'provider_not_documented');
    }

    public static function unchanged(): self
    {
        return new self('That is the budget it already has.', 'budget_unchanged');
    }

    public static function tooLarge(int $allowedPct): self
    {
        return new self(sprintf('One change may move a budget by at most %d%% for this project.', $allowedPct), 'change_too_large');
    }

    public static function aboveCeiling(): self
    {
        return new self('That is above this project’s maximum daily budget.', 'above_ceiling');
    }

    public static function alreadyProposed(): self
    {
        return new self('A change to this campaign is already waiting for a decision.', 'already_proposed');
    }

    public static function coolingDown(): self
    {
        return new self('This campaign had a change proposed within the last day. Wait for the result before proposing another.', 'cooling_down');
    }

    public static function noTarget(): self
    {
        return new self('No target is set for this project, so there is nothing to judge it against.', 'no_target');
    }
}
