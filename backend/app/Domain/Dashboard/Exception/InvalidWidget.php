<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Exception;

use App\Domain\Shared\Exception\DomainException;

/**
 * A widget that cannot answer the question it was given.
 *
 * Each message names what is wrong and what would fix it, because these reach a
 * person composing a dashboard rather than a developer reading a log. "Invalid
 * widget" would leave them guessing which of five controls to change.
 */
final class InvalidWidget extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid_widget';
    }

    public static function needsAMetric(string $type): self
    {
        return new self(sprintf('A %s widget needs at least one metric to show.', strtolower($type)));
    }

    public static function needsTwoMetrics(string $type): self
    {
        return new self(sprintf(
            'A %s widget plots one metric against another, so it needs two.',
            strtolower($type),
        ));
    }

    public static function cannotShowDimension(string $type, ?string $dimension): self
    {
        if ($dimension === null) {
            return new self(sprintf('A %s widget needs something to group by.', strtolower($type)));
        }

        return new self(sprintf(
            'A %s widget cannot be grouped by %s. Choose a different grouping or chart type.',
            strtolower($type),
            strtolower($dimension),
        ));
    }

    public static function overlaps(string $widget, string $other): self
    {
        return new self(sprintf(
            '"%s" would sit on top of "%s". Move one of them, or make it narrower.',
            $widget,
            $other,
        ));
    }
}
