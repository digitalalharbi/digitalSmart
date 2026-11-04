<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

use DateTimeImmutable;

/** One question put to the stored GA4 acquisition traffic. */
final readonly class Ga4TrafficQuery
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
        /** `channel` (default channel group) or `source_medium`. */
        public string $group = 'channel',
        public string $sort = 'sessions',
        public string $direction = 'desc',
        public int $page = 1,
        public int $perPage = 10,
        public ?string $clientId = null,
        public ?string $projectId = null,
        public ?string $sourceId = null,
    ) {}
}
