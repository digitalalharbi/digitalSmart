<?php

declare(strict_types=1);

namespace App\Application\Dashboard\DTO;

use App\Domain\Analytics\Dimension;
use App\Domain\Dashboard\WidgetType;

final readonly class WidgetView
{
    /**
     * @param  list<string>  $metrics
     * @param  array<string, mixed>|null  $filterOverrides
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public string $id,
        public ?string $title,
        public WidgetType $type,
        public array $metrics,
        public ?Dimension $dimension,
        public ?array $filterOverrides,
        public int $column,
        public int $row,
        public int $width,
        public int $height,
        public array $options,
    ) {}
}
