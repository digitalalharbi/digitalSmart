<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exception;

use RuntimeException;

/**
 * A visitor asked a shared report to show something it was never allowed to show.
 *
 * Filters narrow and never widen, so a request that names a platform, a campaign, a store or a day outside the link's
 * own scope is refused rather than quietly dropped: a page that silently ignored it would show figures the visitor
 * believes were filtered, and a refusal is also the only thing that tells a probe it found an edge.
 */
final class FilterOutsideScope extends RuntimeException
{
    public static function period(): self
    {
        return new self('That period is outside what this report covers.');
    }

    public static function unknown(string $what): self
    {
        return new self(sprintf('That %s is not part of this report.', $what));
    }
}
