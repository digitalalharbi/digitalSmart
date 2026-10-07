<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exception;

use App\Domain\Operations\TaskSubjectType;
use RuntimeException;

/** A link to what a task is about that could not be honoured. */
final class InvalidTaskSubject extends RuntimeException
{
    /** @param list<string> $allowed */
    private function __construct(string $message, public readonly array $allowed)
    {
        parent::__construct($message);
    }

    /**
     * A type with no identifier, or an identifier with no type.
     *
     * Refused rather than dropped. Dropping half of it produces a task detached from the thing it was
     * created for, which is indistinguishable from a task nobody linked.
     */
    public static function incomplete(): self
    {
        return new self(
            'Say both what the task is about and which one. Half a link renders as a link and goes nowhere.',
            TaskSubjectType::values(),
        );
    }

    public static function unknownType(): self
    {
        return new self('Unknown subject type.', TaskSubjectType::values());
    }
}
