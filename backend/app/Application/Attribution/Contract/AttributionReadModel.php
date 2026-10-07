<?php

declare(strict_types=1);

namespace App\Application\Attribution\Contract;

use App\Application\Attribution\DTO\AttributionQuery;
use App\Application\Attribution\DTO\AttributionResult;

interface AttributionReadModel
{
    /**
     * Attribute a period's revenue under one model.
     *
     * The rows and the coverage come back together from one call, deliberately: they are computed
     * from the same scan, and a caller able to fetch one without the other would eventually render
     * attributed revenue with no statement of how much of the period it accounts for.
     */
    public function attribute(AttributionQuery $query): AttributionResult;
}
