<?php

declare(strict_types=1);

namespace App\Application\Attribution\DTO;

use App\Domain\Commerce\RevenueSource;
use DateTimeImmutable;

/**
 * The same period under every model that can be computed, side by side.
 *
 * The most honest surface this phase produces, and the reason it exists. Each model on its own
 * invites a reader to treat its numbers as what happened; all of them together make the one true
 * statement available — that attribution is a choice, and here is how much that choice is worth on
 * this client's own campaigns.
 */
final readonly class ComparisonView
{
    /**
     * @param  list<string>  $models  the model values compared, in the order shown
     * @param  list<ModelDisagreement>  $campaigns
     * @param  array<string, AttributionView>  $views  keyed by model value
     */
    public function __construct(
        public array $models,
        public array $campaigns,
        public array $views,
        public RevenueSource $revenueSource,
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
    ) {}

    /** Campaigns where the choice of model changes the figure enough to change a decision. */
    public function materialDisagreements(): int
    {
        return count(array_filter(
            $this->campaigns,
            static fn (ModelDisagreement $row): bool => $row->isMaterial(),
        ));
    }
}
