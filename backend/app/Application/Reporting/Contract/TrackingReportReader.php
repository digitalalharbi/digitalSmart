<?php

declare(strict_types=1);

namespace App\Application\Reporting\Contract;

use App\Application\Reporting\DTO\TrackingSection;
use App\Domain\Advertising\MetricSource;
use App\Domain\Reporting\TrackingView;
use DateTimeImmutable;

interface TrackingReportReader
{
    /**
     * @return array{section: TrackingSection, caveats: list<string>, source?: MetricSource}
     */
    public function read(string $workspaceId, string $clientId, TrackingView $view, DateTimeImmutable $from, DateTimeImmutable $until): array;
}
