<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

use DateTimeImmutable;

/**
 * The facts about one measurement source that a diagnosis needs.
 *
 * A narrow input rather than the whole record, so the health engine cannot quietly start
 * depending on something it has no business reading — and so a test can state a situation in
 * four lines.
 */
final readonly class SourceFacts
{
    public function __construct(
        public string $id,
        public string $name,
        public MeasurementSourceKind $kind,
        public ?DateTimeImmutable $lastSyncedAt = null,
        /**
         * Null means "this kind of source does not report events", not "no events arrived".
         * A tag manager reports configuration; calling it silent would be a permanent false
         * alarm.
         */
        public ?DateTimeImmutable $lastEventAt = null,
    ) {}
}
