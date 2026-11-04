<?php

declare(strict_types=1);

namespace App\Application\Measurement\Contract;

use App\Application\Measurement\DTO\Ga4TrafficQuery;
use App\Application\Measurement\DTO\Ga4TrafficView;
use App\Application\Measurement\DTO\MeasurementSourceView;

interface Ga4TrafficReadModel
{
    /**
     * Read the stored traffic of exactly these sources.
     *
     * The caller passes the sources the reader may see; the read model never widens them. An empty list is an empty
     * answer, not a workspace-wide one.
     *
     * @param  list<MeasurementSourceView>  $sources
     */
    public function read(string $workspaceId, array $sources, Ga4TrafficQuery $query): Ga4TrafficView;
}
