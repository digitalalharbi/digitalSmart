<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

use App\Application\Attribution\DTO\AttributedCampaignRow;
use App\Domain\Attribution\AttributionCoverage;
use App\Domain\Attribution\AttributionModel;
use App\Domain\Commerce\RevenueSource;

/**
 * What an attribution section carries: the model it was read with, how much of the period it could explain, and the
 * campaigns it credits.
 *
 * Coverage travels with the rows, always. An attributed total read without the share of revenue it describes is the
 * specific way this section could mislead, so the two are one object.
 */
final readonly class AttributionFigures
{
    /** @param  list<AttributedCampaignRow>  $rows */
    public function __construct(
        public AttributionModel $model,
        public RevenueSource $revenueSource,
        public AttributionCoverage $coverage,
        public array $rows,
        public ?int $unmatchedSpendMinor,
        public ?string $spendCurrency,
        public int $lookbackDays,
    ) {}

    public function isEmpty(): bool
    {
        return $this->coverage->conversions === 0;
    }

    /**
     * The plain label/text pairs a spreadsheet or a slide prints, in reading order.
     *
     * @return list<array{label: string, text: ?string}>
     */
    public function facts(): array
    {
        $percent = static fn (?float $value): ?string => $value === null ? null : rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.').'%';

        return [
            ['label' => 'Attribution model', 'text' => $this->model->label()],
            ['label' => 'Revenue source', 'text' => $this->revenueSource->label()],
            ['label' => 'Conversions in the period', 'text' => (string) $this->coverage->conversions],
            ['label' => 'Conversions carrying marketing evidence', 'text' => $percent($this->coverage->conversionCoverage())],
            ['label' => 'Revenue the campaigns account for', 'text' => $percent($this->coverage->revenueCoverage())],
            ['label' => 'Conversions naming a campaign', 'text' => $percent($this->coverage->campaignNamedShare())],
        ];
    }
}
