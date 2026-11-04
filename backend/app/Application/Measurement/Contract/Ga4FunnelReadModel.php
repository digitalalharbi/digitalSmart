<?php

declare(strict_types=1);

namespace App\Application\Measurement\Contract;

use App\Application\Measurement\DTO\MeasurementSourceView;
use DateTimeImmutable;

interface Ga4FunnelReadModel
{
    /**
     * The four Google-documented e-commerce events, counted over the period for exactly these sources.
     *
     * Event counts, not users: a person who adds three items to a cart is three events, so the figures here are never a
     * conversion rate of people and nothing downstream may call them one. A stage the property reported no rows for is null
     * ("not reported"), which is not the same as a stage that reported zero.
     *
     * @param  list<MeasurementSourceView>  $sources
     * @return array{stages: list<array{event: string, count: ?int}>, days_present: int, days_in_period: int, source: ?string, time_zones: list<string>}
     */
    public function read(string $workspaceId, array $sources, DateTimeImmutable $from, DateTimeImmutable $until): array;
}
