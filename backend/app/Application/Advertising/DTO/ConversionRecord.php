<?php

declare(strict_types=1);

namespace App\Application\Advertising\DTO;

use App\Domain\Advertising\ConversionActionType;

/**
 * One conversion action for one entity on one day.
 *
 * Mirrors the shape every platform converges on: an action identifier, a count,
 * sometimes a value, and the attribution window the figure was measured under.
 * Meta returns these as `actions` / `action_values` arrays keyed by
 * `action_type`; Google as conversion actions by category.
 */
final readonly class ConversionRecord
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $rawActionType,
        public float $count,
        public ConversionActionType $actionType = ConversionActionType::Other,
        public ?string $actionName = null,
        public ?int $valueMinor = null,
        public ?string $currency = null,
        /** For example Meta's "7d_click" or "1d_view". */
        public ?string $attributionWindow = null,
        public array $metadata = [],
    ) {}
}
