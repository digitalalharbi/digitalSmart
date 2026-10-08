<?php

declare(strict_types=1);

namespace App\Application\Shared\DTO;

use DateTimeImmutable;

/**
 * A count of queued or failed work, and the age of the oldest piece of it.
 *
 * `oldest` is null for two different reasons and the distinction is load-bearing: either there is nothing
 * waiting, which `count === 0` says, or the driver does not publish the age. `ageIsKnown` tells them apart, so a
 * probe can say "depth only, this driver does not publish ages" rather than implying it checked and found
 * nothing stale.
 */
final readonly class WorkBacklog
{
    private function __construct(
        public int $count,
        public ?DateTimeImmutable $oldest,
        public bool $ageIsKnown,
    ) {}

    public static function of(int $count, ?DateTimeImmutable $oldest): self
    {
        return new self(max(0, $count), $oldest, true);
    }

    /** A driver that answers how many and not how old. */
    public static function countOnly(int $count): self
    {
        return new self(max(0, $count), null, false);
    }

    public static function empty(): self
    {
        return new self(0, null, true);
    }

    public function isEmpty(): bool
    {
        return $this->count === 0;
    }

    /** Seconds the oldest piece has been waiting, or null where that cannot be read. */
    public function waitedSeconds(DateTimeImmutable $now): ?int
    {
        if ($this->oldest === null) {
            return null;
        }

        return max(0, $now->getTimestamp() - $this->oldest->getTimestamp());
    }
}
