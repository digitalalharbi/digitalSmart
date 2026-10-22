<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Application\Analytics\DTO\CreativeRow;
use App\Application\Analytics\DTO\MetricSet;
use App\Application\Analytics\DTO\SeriesPoint;
use App\Domain\Reporting\CreativeView;

/**
 * What a creative section shows, beyond the rows every ranking has.
 *
 * `rows` are the creatives of the view, each with its picture reference, figures, daily click-through and plain verdict
 * — read from the same layer as the Creatives page. `mix` is the format mix and is filled only for that view: money by
 * format when the creatives share one currency, otherwise how many there are of each, because money in two currencies
 * has no share.
 */
final readonly class CreativeSection
{
    /**
     * @param  list<CreativeRow>  $rows
     * @param  list<array{key: string, label: string, value: float}>  $mix
     */
    public function __construct(
        public CreativeView $view,
        public array $rows,
        public array $mix = [],
        public bool $mixIsSpend = true,
        /** How many creatives the selection holds, of which `rows` are the ones this view shows. */
        public int $total = 0,
        /**
         * The scope's own totals and its daily series, from the analytics layer — the figures a hero and a trend
         * draw. Read for the gallery view only; a second copy on every view would be the same numbers four times.
         */
        public ?MetricSet $summary = null,
        /** @var list<SeriesPoint> */
        public array $series = [],
        /**
         * Per platform: how many of the shown creatives, and their spend, results, click-through and cost per result
         * — sums over rows already read, like the format mix. Filled for the platform-mix view.
         *
         * @var list<array{key: string, label: string, creatives: int, spend: float, results: float, ctr: ?float, cpa: ?float, currency: ?string}>
         */
        public array $platformMix = [],
    ) {}
}
