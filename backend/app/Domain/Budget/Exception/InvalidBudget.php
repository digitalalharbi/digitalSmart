<?php

declare(strict_types=1);

namespace App\Domain\Budget\Exception;

use App\Domain\Shared\Exception\DomainException;

/**
 * A budget that could not mean what it says.
 *
 * These messages reach a project manager agreeing a figure with a client, so each one
 * names the thing to change. "Invalid budget" would leave them guessing which of five
 * fields is wrong.
 */
final class InvalidBudget extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid_budget';
    }

    public static function periodEndsBeforeItStarts(): self
    {
        return new self('A budget period has to end on or after the day it starts.');
    }

    public static function amountMustBePositive(): self
    {
        return new self('A budget needs an amount above zero. Delete it instead of setting it to nothing.');
    }

    public static function amountIsFixedOnceApproved(): self
    {
        return new self(
            'This budget has been approved, so its amount and period can no longer be changed. '
            .'Archive it and approve a new one, which keeps the record of what was agreed.'
        );
    }

    public static function alreadyApproved(): self
    {
        return new self('This budget is already approved.');
    }

    public static function cannotApproveArchived(): self
    {
        return new self('An archived budget cannot be approved. Create a new one.');
    }

    public static function approvedMustBeArchived(): self
    {
        return new self(
            'This budget has been approved and spend is measured against it, so it cannot be deleted. '
            .'Archive it instead, which keeps the record of what was agreed.'
        );
    }

    public static function archivedCannotChange(): self
    {
        return new self('This budget is archived. The spend recorded against it still has to reconcile, so it is kept as it was.');
    }
}
