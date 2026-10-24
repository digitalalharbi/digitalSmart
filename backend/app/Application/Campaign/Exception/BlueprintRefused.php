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
}
