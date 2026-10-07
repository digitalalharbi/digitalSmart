<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Attribution;

use App\Application\Attribution\DTO\AttributedCampaignRow;
use App\Application\Attribution\DTO\AttributionView;
use App\Application\Attribution\DTO\ComparisonView;
use App\Application\Attribution\DTO\ModelDisagreement;
use App\Domain\Attribution\AttributionCoverage;
use App\Domain\Attribution\AttributionModel;

final readonly class AttributionResource
{
    /**
     * The model catalogue.
     *
     * Published so the interface never hard-codes attribution vocabulary — the same rule the provider
     * registry establishes. It includes the models that **cannot** be computed, with what is missing
     * and what would unblock them, because an option that is absent gets asked for again every
     * quarter and an option that is present and explained does not.
     *
     * @return list<array<string, mixed>>
     */
    public static function models(): array
    {
        return array_map(static fn (AttributionModel $model): array => [
            'model' => $model->value,
            'label' => $model->label(),
            'description' => $model->description(),
            /*
             * The field every surface showing one of these figures is obliged to act on. False for
             * exactly one model, because exactly one of them reports what the order said rather than
             * deciding what it meant.
             */
            'is_modelled' => $model->isModelled(),
            'is_available' => $model->isAvailable(),
            'needs_person' => $model->needsPerson(),
            'requires' => $model->requires()->value,
            'requires_label' => $model->requires()->label(),
            'missing' => $model->requires()->missing(),
            'would_unblock' => $model->requires()->wouldUnblock(),
        ], AttributionModel::cases());
    }

    /** @return array<string, mixed> */
    public static function view(AttributionView $view): array
    {
        return [
            'model' => $view->model->value,
            'model_label' => $view->model->label(),
            'is_modelled' => $view->model->isModelled(),
            'revenue_source' => $view->revenueSource->value,
            'revenue_source_label' => $view->revenueSource->label(),
            'is_invoiced' => $view->revenueSource->isInvoiced(),
            'lookback_days' => $view->lookbackDays,
            'from' => $view->from->format('Y-m-d'),
            'until' => $view->until->format('Y-m-d'),
            'coverage' => self::coverage($view->coverage),
            'rows' => array_map(
                static fn (AttributedCampaignRow $row): array => self::row($row),
                $view->rows,
            ),
            /*
             * Spend with no attributed revenue against it. Half the question an agency is asked, and
             * the half a report that only lists winning campaigns leaves out.
             */
            'unmatched_spend_minor' => $view->unmatchedSpendMinor,
            'spend_currency' => $view->spendCurrency,
            'caveats' => $view->caveats,
        ];
    }

    /** @return array<string, mixed> */
    public static function comparison(ComparisonView $view): array
    {
        return [
            'models' => $view->models,
            'revenue_source' => $view->revenueSource->value,
            'from' => $view->from->format('Y-m-d'),
            'until' => $view->until->format('Y-m-d'),
            /*
             * The number the surface leads with. Not how much each model says, but on how many
             * campaigns the choice of model changes the figure enough to change a decision.
             */
            'material_disagreements' => $view->materialDisagreements(),
            'campaigns' => array_map(static fn (ModelDisagreement $row): array => [
                'label' => $row->label,
                /*
                 * Null for a model means the campaign was not among that model's rows — which is not
                 * the same as that model giving it zero, and a breakdown limited to the top rows makes
                 * the difference matter.
                 */
                'revenue_minor_by_model' => $row->revenueMinorByModel,
                'currency' => $row->currency,
                'highest_minor' => $row->highestMinor(),
                'lowest_minor' => $row->lowestMinor(),
                'spread_percent' => $row->spreadPercent(),
                'is_material' => $row->isMaterial(),
            ], $view->campaigns),
            'views' => array_map(static fn (AttributionView $one): array => self::view($one), $view->views),
        ];
    }

    /** @return array<string, mixed> */
    private static function coverage(AttributionCoverage $coverage): array
    {
        return [
            'conversions' => $coverage->conversions,
            'attributed_conversions' => $coverage->attributedConversions,
            'campaign_named_conversions' => $coverage->campaignNamedConversions,
            'revenue_minor' => $coverage->revenueMinor,
            'attributed_revenue_minor' => $coverage->attributedRevenueMinor,
            /*
             * Revenue the period produced that no campaign can be credited with. Published as its own
             * figure rather than left to subtraction, because it is the number a reader most needs and
             * least expects.
             */
            'unattributed_revenue_minor' => $coverage->unattributedRevenueMinor(),
            'currency' => $coverage->currency,
            'conversion_coverage' => $coverage->conversionCoverage(),
            'revenue_coverage' => $coverage->revenueCoverage(),
            'campaign_named_share' => $coverage->campaignNamedShare(),
            'weakest_evidence' => $coverage->weakestEvidence->value,
            'weakest_evidence_label' => $coverage->weakestEvidence->label(),
            'confidence' => $coverage->confidence,
            'is_reportable' => $coverage->isReportable(),
            'reportable_threshold' => AttributionCoverage::REPORTABLE_PERCENT,
        ];
    }

    /** @return array<string, mixed> */
    private static function row(AttributedCampaignRow $row): array
    {
        return [
            'label' => $row->label,
            'link' => $row->link->value,
            'link_label' => $row->link->label(),
            /*
             * Why a row carries no spend, beside the empty cell rather than in a footnote. "No campaign
             * of this name exists in the connected accounts" is a tagging problem somebody can fix;
             * a blank is a report somebody distrusts.
             */
            'link_explanation' => $row->link->explanation(),
            'joins_spend' => $row->link->joinsSpend(),
            'provider' => $row->provider,
            'conversions' => $row->conversions,
            'revenue_minor' => $row->revenueMinor,
            'currency' => $row->currency,
            'average_revenue_minor' => $row->averageRevenueMinor(),
            'evidence' => $row->evidence->value,
            'evidence_label' => $row->evidence->label(),
            'confidence' => $row->evidence->confidence(),
            'spend_minor' => $row->spendMinor,
            'spend_source' => $row->spendSource,
            // Null wherever either side is missing, the currencies differ, or spend is zero.
            'roas' => $row->roas(),
            'cost_per_conversion_minor' => $row->costPerConversionMinor(),
        ];
    }
}
