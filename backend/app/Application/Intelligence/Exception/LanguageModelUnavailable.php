<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Exception;

use RuntimeException;

/**
 * The model could not be reached, or refused.
 *
 * Never surfaced to a reader as an error. The assistant's findings are computed without a model, so a model that
 * is missing, rate-limited or overloaded costs a reader their prose and nothing else — and the response says who
 * wrote the prose it did get. An assistant that returned an error page because a third party was busy would be an
 * assistant that had made a language model a dependency of reading your own figures.
 */
final class LanguageModelUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('No language model is configured for this workspace.');
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
