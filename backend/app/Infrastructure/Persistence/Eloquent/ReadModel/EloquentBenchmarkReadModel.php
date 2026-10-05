<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Budget\Contract\BenchmarkReadModel;
use App\Application\Budget\DTO\BenchmarkQuery;
use App\Application\Budget\DTO\BenchmarkSet;
use App\Application\Budget\DTO\ProviderBenchmark;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\MetricSource;
use App\Domain\Budget\Forecast\Confidence;
use App\Domain\Budget\Forecast\RateCard;
use App\Domain\Integration\Provider;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Forecast rates, derived from what this workspace has actually spent.
 *
 * One query, one row per provider per day, and the statistics computed here rather than
 * in SQL — the volume is tiny and the arithmetic is worth reading.
 *
 * Three things this refuses to do:
 *
 *   - **Invent a rate.** A provider with no delivery in the window gets no rates and a
 *     reason, not an industry average. There is no industry average in this product.
 *   - **Mix currencies.** Rates are per provider and only from the single currency that
 *     provider reported; a provider reporting in two currencies gets a refusal, because
 *     a cost per click averaged across currencies is not a cost.
 *   - **Average the averages.** A daily cost per click is spend ÷ clicks *for that day*;
 *     the headline rate is total spend ÷ total clicks, which is the weighted figure. The
 *     daily rates are used only for the band, which is what they are honest about.
 */
final readonly class EloquentBenchmarkReadModel implements BenchmarkReadModel
{
    public function __construct(private Clock $clock) {}

    public function observe(string $workspaceId, BenchmarkQuery $query): BenchmarkSet
    {
        $end = $this->clock->now()->setTime(0, 0);
        // Inclusive of both ends, so "90 days" is 90 dates.
        $start = $end->modify('-'.max(1, $query->days - 1).' days');

        $rows = $this->dailyRows($workspaceId, $query, $start->format('Y-m-d'), $end->format('Y-m-d'));

        $benchmarks = [];

        foreach ($this->groupByProvider($rows) as $providerValue => $days) {
            $provider = Provider::tryFrom($providerValue);

            if (! $provider instanceof Provider) {
                continue;
            }

            $benchmarks[$providerValue] = $this->benchmarkFor($provider, $days);
        }

        return new BenchmarkSet(
            windowStart: $start,
            windowEnd: $end,
            byProvider: $benchmarks,
            clientId: $query->clientId,
            projectId: $query->projectId,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function benchmarkFor(Provider $provider, array $days): ProviderBenchmark
    {
        $currencies = [];

        foreach ($days as $day) {
            $currency = (string) ($day['currency'] ?? '');

            if ($currency !== '') {
                $currencies[$currency] = true;
            }
        }

        $sources = [];

        foreach ($days as $day) {
            foreach (explode(',', (string) ($day['sources'] ?? '')) as $raw) {
                $source = MetricSource::tryFrom(trim($raw));

                if ($source instanceof MetricSource) {
                    $sources[] = $source;
                }
            }
        }

        $source = MetricSource::weakest($sources);

        if (count($currencies) > 1) {
            return new ProviderBenchmark(
                provider: $provider,
                rates: new RateCard,
                confidence: Confidence::None,
                observedDays: count($days),
                currency: null,
                source: $source,
                unavailableReason: 'This platform reported in '.implode(' and ', array_keys($currencies))
                    .'. A cost per click averaged across currencies is not a cost, so no rate is derived.',
            );
        }

        $currency = array_key_first($currencies);

        $spend = $this->sum($days, 'spend_minor');
        $impressions = $this->sum($days, 'impressions');
        $clicks = $this->sum($days, 'clicks');
        $conversions = $this->sumFloat($days, 'conversions');

        /*
         * Weighted, not averaged: total spend over total clicks. An average of daily
         * rates would let one quiet day with two clicks count as much as a busy one.
         */
        $cpc = $clicks > 0 && $spend > 0 ? (int) round($spend / $clicks) : null;
        $cpm = $impressions > 0 && $spend > 0 ? (int) round($spend / $impressions * 1000) : null;
        $ctr = $impressions > 0 && $clicks > 0 ? $clicks / $impressions : null;
        $cvr = $clicks > 0 && $conversions > 0.0 ? $conversions / $clicks : null;
        $cpa = $conversions > 0.0 && $spend > 0 ? (int) round($spend / $conversions) : null;

        $cpcBand = $this->band($days, 'spend_minor', 'clicks');
        $cpmBand = $this->band($days, 'spend_minor', 'impressions', 1000);
        $cvrBand = $this->ratioBand($days, 'conversions', 'clicks');

        $rates = new RateCard(
            cpmMinor: $cpm,
            cpmLowMinor: $cpmBand[0],
            cpmHighMinor: $cpmBand[1],
            cpcMinor: $cpc,
            cpcLowMinor: $cpcBand[0],
            cpcHighMinor: $cpcBand[1],
            ctr: $ctr,
            cvr: $cvr,
            cvrLow: $cvrBand[0],
            cvrHigh: $cvrBand[1],
            cpaMinor: $cpa,
        );

        $observedDays = count($days);

        return new ProviderBenchmark(
            provider: $provider,
            rates: $rates,
            confidence: Confidence::forSample($observedDays, $rates->cpcSpread()),
            observedDays: $observedDays,
            currency: $currency === null ? null : (string) $currency,
            source: $source,
            spendMinor: $spend > 0 ? $spend : null,
            impressions: $impressions > 0 ? $impressions : null,
            clicks: $clicks > 0 ? $clicks : null,
            conversions: $conversions > 0.0 ? $conversions : null,
            unavailableReason: $observedDays < Confidence::MINIMUM_DAYS
                ? sprintf(
                    'Only %d day%s of delivery in this window. At least %d are needed before a rate means anything.',
                    $observedDays,
                    $observedDays === 1 ? '' : 's',
                    Confidence::MINIMUM_DAYS,
                )
                : null,
        );
    }

    /**
     * One row per provider per day, at campaign level only.
     *
     * Campaign level for the same reason every other total here uses it: the fact table
     * holds the same spend three times, once per level, and a rate built from all three
     * would be right only by coincidence.
     *
     * @return list<array<string, mixed>>
     */
    private function dailyRows(string $workspaceId, BenchmarkQuery $query, string $start, string $end): array
    {
        $builder = DB::table('daily_metrics')
            ->where('workspace_id', $workspaceId)
            ->where('entity_type', 'campaign')
            ->whereBetween('date', [$start, $end]);

        if ($query->providers !== []) {
            $builder->whereIn('provider', $query->providers);
        }

        if ($query->projectId !== null) {
            $builder->whereIn('external_account_id', function (Builder $sub) use ($query, $workspaceId): void {
                $sub->select('external_account_id')
                    ->from('project_integration_bindings')
                    ->where('workspace_id', $workspaceId)
                    ->where('project_id', $query->projectId);
            });
        } elseif ($query->clientId !== null) {
            $builder->whereIn('external_account_id', function (Builder $sub) use ($query, $workspaceId): void {
                $sub->select('bindings.external_account_id')
                    ->from('project_integration_bindings as bindings')
                    ->join('projects', 'projects.id', '=', 'bindings.project_id')
                    ->where('bindings.workspace_id', $workspaceId)
                    ->where('projects.client_id', $query->clientId);
            });
        }

        $rows = $builder
            ->selectRaw(
                'provider, date, currency, sum(spend_minor) as spend_minor, '
                .'sum(impressions) as impressions, sum(clicks) as clicks, sum(conversions) as conversions, '
                ."string_agg(distinct source, ',') as sources"
            )
            ->groupBy('provider', 'date', 'currency')
            // A day with no spend teaches nothing about cost.
            ->havingRaw('sum(spend_minor) > 0')
            ->orderBy('provider')
            ->orderBy('date')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $out[] = $values;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByProvider(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $provider = (string) ($row['provider'] ?? '');

            if ($provider === '') {
                continue;
            }

            $grouped[$provider][] = $row;
        }

        return $grouped;
    }

    /**
     * The 25th and 75th percentile of a daily cost.
     *
     * A real statistic about this account's own variation, rather than a percentage band
     * chosen to look like a range. Days where the denominator is zero are left out: a day
     * with spend and no clicks has an infinite cost per click, which is true and useless.
     *
     * @param  list<array<string, mixed>>  $days
     * @return array{?int, ?int}
     */
    private function band(array $days, string $numerator, string $denominator, int $scale = 1): array
    {
        $rates = [];

        foreach ($days as $day) {
            $top = (float) ($day[$numerator] ?? 0);
            $bottom = (float) ($day[$denominator] ?? 0);

            if ($top > 0 && $bottom > 0) {
                $rates[] = $top / $bottom * $scale;
            }
        }

        if (count($rates) < 2) {
            // One observation has no spread. Null says that rather than implying a band.
            return [null, null];
        }

        sort($rates);

        return [
            (int) round($this->percentile($rates, 0.25)),
            (int) round($this->percentile($rates, 0.75)),
        ];
    }

    /**
     * The same, for a ratio that stays a ratio.
     *
     * @param  list<array<string, mixed>>  $days
     * @return array{?float, ?float}
     */
    private function ratioBand(array $days, string $numerator, string $denominator): array
    {
        $rates = [];

        foreach ($days as $day) {
            $top = (float) ($day[$numerator] ?? 0);
            $bottom = (float) ($day[$denominator] ?? 0);

            if ($top > 0 && $bottom > 0) {
                $rates[] = $top / $bottom;
            }
        }

        if (count($rates) < 2) {
            return [null, null];
        }

        sort($rates);

        return [$this->percentile($rates, 0.25), $this->percentile($rates, 0.75)];
    }

    /**
     * Linear interpolation between the two nearest observations.
     *
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $p): float
    {
        $position = $p * (count($sorted) - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($position - $lower);
    }

    /** @param list<array<string, mixed>> $days */
    private function sum(array $days, string $key): int
    {
        $total = 0;

        foreach ($days as $day) {
            $total += (int) ($day[$key] ?? 0);
        }

        return $total;
    }

    /** @param list<array<string, mixed>> $days */
    private function sumFloat(array $days, string $key): float
    {
        $total = 0.0;

        foreach ($days as $day) {
            $total += (float) ($day[$key] ?? 0);
        }

        return $total;
    }
}
