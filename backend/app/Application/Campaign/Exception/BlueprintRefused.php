<?php

declare(strict_types=1);

namespace App\Application\Campaign\Exception;

use App\Domain\Shared\Exception\DomainException;

/** A campaign plan that cannot be saved or moved as asked, with what to change. */
final class BlueprintRefused extends DomainException
{
    /** @param list<string> $missing */
    public function __construct(string $message, private readonly string $reasonCode = 'blueprint_refused', public readonly array $missing = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reasonCode;
    }

    public static function accountNotBound(): self
    {
        return new self('Every ad account in a plan must be bound to its project. Bind the account to the project in Integrations first.', 'account_not_bound');
    }

    public static function referenceOutsideProject(string $what): self
    {
        return new self(sprintf('That %s does not belong to this project or its client.', $what), 'reference_outside_project');
    }

    public static function notEditable(string $status): self
    {
        return new self(sprintf('A plan that is %s cannot be edited. Return it to draft or start a new one.', str_replace('_', ' ', $status)), 'not_editable');
    }

    public static function cannotMove(string $from, string $to): self
    {
        return new self(sprintf('A plan cannot go from %s to %s.', str_replace('_', ' ', $from), str_replace('_', ' ', $to)), 'invalid_transition');
    }

    /** @param list<string> $missing */
    public static function incomplete(array $missing): self
    {
        return new self('This plan is not complete enough to review: '.implode(', ', $missing).'.', 'blueprint_incomplete', $missing);
    }

    public static function reasonRequired(): self
    {
        return new self('Say why. The reason stays with the plan.', 'reason_required');
    }

    /** @param list<string> $blocked */
    public static function preflightBlocked(array $blocked): self
    {
        return new self('This plan cannot be approved or launched while the preflight has something to fix: '.implode(' ', $blocked), 'preflight_blocked', $blocked);
    }

    public static function notApproved(): self
    {
        return new self('Only an approved plan can be launched. Submit it for review and have it approved first.', 'not_approved');
    }

    public static function approvalStale(): self
    {
        return new self('The plan, or what it would write, changed after it was approved. It is back in review; approve it again.', 'approval_stale');
    }

    public static function notRetryable(string $status): self
    {
        return new self(sprintf('An execution that is %s cannot be retried. An unknown outcome is reconciled first, so nothing is created twice.', str_replace('_', ' ', $status)), 'not_retryable');
    }

    public static function notReconcilable(string $status): self
    {
        return new self(sprintf('Only an unknown outcome is reconciled; this execution is %s.', str_replace('_', ' ', $status)), 'not_reconcilable');
    }
}
