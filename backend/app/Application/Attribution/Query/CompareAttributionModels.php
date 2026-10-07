<?php

declare(strict_types=1);

namespace App\Application\Attribution\Query;

use App\Application\Attribution\DTO\AttributionView;
use App\Application\Attribution\DTO\ComparisonView;
use App\Application\Attribution\DTO\ModelDisagreement;
use App\Domain\Attribution\AttributionModel;
use App\Domain\Commerce\RevenueSource;
use DateTimeImmutable;

/**
 * Run every available model over one period and report where they disagree.
 *
 * A deliberate piece of product design rather than a convenience. An attribution page that offers a
 * model selector teaches a reader that one of the options is the right one; this surface teaches the
 * opposite, which is the truth: the models disagree by construction, and the size of the
 * disagreement on this client's own campaigns is the only way to say how much the choice matters.
 *
 * Built on `GetAttribution` rather than beside it, so the permission check, the refusals, the
 * coverage and the caveats are the same ones — a comparison that computed its figures differently
 * from the single-model view would be a second implementation to keep honest.
 */
final readonly class CompareAttributionModels
{
    public function __construct(private GetAttribution $attribution) {}

    public function __invoke(
        RevenueSource $revenueSource,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        ?string $clientId = null,
        int $lookbackDays = 30,
        int $limit = 20,
    ): ComparisonView {
        $views = [];

        foreach (AttributionModel::available() as $model) {
            $views[$model->value] = $this->attribution(
                $model,
                $revenueSource,
                $from,
                $until,
                $clientId,
                $lookbackDays,
                $limit,
            );
        }

        return new ComparisonView(
            models: array_map(static fn (AttributionModel $m): string => $m->value, AttributionModel::available()),
            campaigns: $this->disagreements($views, $limit),
            views: $views,
            revenueSource: $revenueSource,
            from: $from,
            until: $until,
        );
    }

    private function attribution(
        AttributionModel $model,
        RevenueSource $revenueSource,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        ?string $clientId,
        int $lookbackDays,
        int $limit,
    ): AttributionView {
        return ($this->attribution)($model, $revenueSource, $from, $until, $clientId, $lookbackDays, $limit);
    }

    /**
     * One row per campaign any model credited, with what each model gave it.
     *
     * A campaign missing from a model's rows means one of two different things, and the difference
     * decides whether the comparison can report a disagreement at all:
     *
     *  - The model's breakdown was **not** truncated, so it saw every campaign and credited this one
     *    nothing. That is a zero, and a campaign one model credits with half a million while another
     *    credits it with nothing is the most extreme disagreement there is — exactly what this surface
     *    exists to surface.
     *  - The model's breakdown **was** truncated by the row limit, so the campaign may simply have
     *    fallen outside the top rows. That is unknown, and recording it as zero would manufacture a
     *    disagreement out of a pagination artefact.
     *
     * Truncation is detectable: a model whose row count reached the limit may have more.
     *
     * @param  array<string, AttributionView>  $views
     * @return list<ModelDisagreement>
     */
    private function disagreements(array $views, int $limit): array
    {
        $byLabel = [];
        $currencies = [];
        $truncated = [];

        foreach ($views as $model => $view) {
            $truncated[$model] = count($view->rows) >= $limit;

            foreach ($view->rows as $row) {
                $byLabel[$row->label][$model] = $row->revenueMinor;

                if ($row->currency !== null) {
                    $currencies[$row->label] = $row->currency;
                }
            }
        }

        $rows = [];

        foreach ($byLabel as $label => $revenueByModel) {
            foreach (array_keys($views) as $model) {
                if (array_key_exists($model, $revenueByModel)) {
                    continue;
                }

                // Zero where the model saw everything; null where it may not have.
                $revenueByModel[$model] = $truncated[$model] ? null : 0;
            }

            $rows[] = new ModelDisagreement(
                label: (string) $label,
                revenueMinorByModel: $revenueByModel,
                currency: $currencies[$label] ?? null,
            );
        }

        /*
         * Biggest disagreement first. The campaigns where the models agree need no attention, and a
         * list sorted by revenue would bury the one row that changes a decision.
         */
        usort(
            $rows,
            static fn (ModelDisagreement $a, ModelDisagreement $b): int => ($b->spreadPercent() ?? -1.0)
                <=> ($a->spreadPercent() ?? -1.0),
        );

        return $rows;
    }
}
