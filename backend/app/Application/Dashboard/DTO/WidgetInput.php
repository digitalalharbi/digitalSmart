<?php

declare(strict_types=1);

namespace App\Application\Dashboard\DTO;

use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Dashboard\GridPlacement;
use App\Domain\Dashboard\WidgetType;

/** One widget as a caller described it. */
final readonly class WidgetInput
{
    /**
     * @param  list<Metric>  $metrics
     * @param  array<string, mixed>|null  $filterOverrides  null inherits the dashboard's
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public WidgetType $type,
        public array $metrics,
        public GridPlacement $placement,
        public ?string $id = null,
        public ?string $title = null,
        public ?Dimension $dimension = null,
        public ?array $filterOverrides = null,
        public array $options = [],
    ) {}
}
