<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

use App\Domain\Dashboard\Exception\InvalidPlacement;

/**
 * Where a widget sits on the 12-column canvas.
 *
 * Validated here rather than trusted from the client, because a widget placed
 * outside the grid is not a cosmetic problem: it disappears from the dashboard
 * entirely, and the reader has no way to know it was ever there.
 */
final readonly class GridPlacement
{
    public const COLUMNS = 12;

    public const MIN_WIDTH = 3;

    public const MIN_HEIGHT = 1;

    public const MAX_HEIGHT = 6;

    public function __construct(
        public int $column,
        public int $row,
        public int $width,
        public int $height,
    ) {
        if ($column < 0 || $column >= self::COLUMNS) {
            throw new InvalidPlacement(sprintf(
                'A widget must start within the %d-column grid; %d is outside it.',
                self::COLUMNS,
                $column,
            ));
        }

        if ($width < self::MIN_WIDTH || $width > self::COLUMNS) {
            throw new InvalidPlacement(sprintf(
                'A widget must be between %d and %d columns wide; %d is not.',
                self::MIN_WIDTH,
                self::COLUMNS,
                $width,
            ));
        }

        if ($column + $width > self::COLUMNS) {
            // Silently clamping would move a widget the reader deliberately placed.
            throw new InvalidPlacement(sprintf(
                'A widget %d columns wide cannot start at column %d: it would run off the grid.',
                $width,
                $column,
            ));
        }

        if ($row < 0) {
            throw new InvalidPlacement('A widget cannot sit above the top of the dashboard.');
        }

        if ($height < self::MIN_HEIGHT || $height > self::MAX_HEIGHT) {
            throw new InvalidPlacement(sprintf(
                'A widget must be between %d and %d rows tall; %d is not.',
                self::MIN_HEIGHT,
                self::MAX_HEIGHT,
                $height,
            ));
        }
    }

    /** A sensible default: half width, two rows — enough for a chart with a legend. */
    public static function default(int $row = 0): self
    {
        return new self(column: 0, row: $row, width: 6, height: 2);
    }

    public function endColumn(): int
    {
        return $this->column + $this->width;
    }

    /** Whether two widgets on the same row would overlap. */
    public function overlaps(self $other): bool
    {
        if ($this->row !== $other->row) {
            return false;
        }

        return $this->column < $other->endColumn() && $other->column < $this->endColumn();
    }
}
