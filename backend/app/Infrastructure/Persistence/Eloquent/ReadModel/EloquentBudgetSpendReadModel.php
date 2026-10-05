<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Budget\Contract\BudgetSpendReadModel;
use App\Application\Budget\DTO\BudgetView;
use App\Application\Budget\DTO\SpendTotal;
use App\Domain\Advertising\MetricSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What was actually spent inside a budget, read from the synced fact table.
 *
 * Three things decide whether a figure may be stated at all, and all three are
 * enforced here rather than hoped for upstream:
 *
 *   - **One level only.** `daily_metrics` holds a row per entity per day at campaign,
 *     ad group *and* ad level. Summing across levels triples the spend, so every total
 *     reads the campaign level and nothing else.
 *   - **One currency.** Adding riyals to dollars produces a number with no unit. A
 *     scope covering two currencies gets a refusal with the reason, not a total.
 *   - **One source tier.** Sandbox figures do not add to live ones. The weakest source
 *     present labels the total, and a mix that cannot combine is refused — otherwise a
 *     fixture becomes part of a client's spend.
 */
final readonly class EloquentBudgetSpendReadModel implements BudgetSpendReadModel
{
    public function totalFor(string $workspaceId, BudgetView $budget): SpendTotal
    {
        $row = $this->scoped($workspaceId, $budget, 'campaign')
            ->selectRaw($this->aggregate())
            ->first();

        return $this->toTotal($row === null ? null : (array) $row, $budget);
    }

    /** @return array<string, SpendTotal> */
    public function byProvider(string $workspaceId, BudgetView $budget): array
    {
        return $this->grouped($workspaceId, $budget, 'campaign', 'provider');
    }

    /** @return array<string, SpendTotal> */
    public function byCampaign(string $workspaceId, BudgetView $budget): array
    {
        return $this->grouped($workspaceId, $budget, 'campaign', 'campaign_id');
    }

    /** @return array<string, SpendTotal> */
    public function byAdGroup(string $workspaceId, BudgetView $budget): array
    {
        /*
         * Ad group level, which is a different set of rows — not the campaign rows
         * grouped differently. Asking a provider for ad-group figures and asking for
         * campaign figures returns different numbers, and this product does not pretend
         * otherwise.
         */
        return $this->grouped($workspaceId, $budget, 'ad_group', 'ad_group_id');
    }

    /** @return list<array{date: string, spendMinor: int}> */
    public function dailyFor(string $workspaceId, BudgetView $budget): array
    {
        $rows = $this->scoped($workspaceId, $budget, 'campaign')
            ->where('currency', $budget->currency)
            ->selectRaw('date, sum(spend_minor) as spend_minor')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $daily = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $spend = $values['spend_minor'] ?? null;

            // A day with no spend reported is left out rather than written as a zero:
            // a pacing chart draws a flat line through gaps, which is the honest shape.
            if ($spend === null) {
                continue;
            }

            $daily[] = [
                'date' => (string) ($values['date'] ?? ''),
                'spendMinor' => (int) $spend,
            ];
        }

        return $daily;
    }

    /**
     * @return array<string, SpendTotal>
     */
    private function grouped(string $workspaceId, BudgetView $budget, string $level, string $column): array
    {
        $rows = $this->scoped($workspaceId, $budget, $level)
            ->whereNotNull($column)
            ->selectRaw($column.' as group_key, '.$this->aggregate())
            ->groupBy($column)
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $key = $values['group_key'] ?? null;

            if ($key === null) {
                continue;
            }

            $totals[(string) $key] = $this->toTotal($values, $budget);
        }

        return $totals;
    }

    /**
     * The rows inside a budget's scope and period.
     *
     * Scope is the project binding model again: a budget tied to a project covers the
     * accounts bound to that project, and a client-level budget covers the accounts
     * bound to any of that client's projects. The binding is the authorisation, not a
     * convenience — an account nobody bound is an account this budget cannot see.
     */
    private function scoped(string $workspaceId, BudgetView $budget, string $level): Builder
    {
        $query = DB::table('daily_metrics')
            ->where('workspace_id', $workspaceId)
            ->where('entity_type', $level)
            ->whereBetween('date', [
                $budget->periodStart->format('Y-m-d'),
                $budget->periodEnd->format('Y-m-d'),
            ]);

        if ($budget->projectId !== null) {
            return $query->whereIn('external_account_id', function (Builder $sub) use ($budget, $workspaceId): void {
                $sub->select('external_account_id')
                    ->from('project_integration_bindings')
                    ->where('workspace_id', $workspaceId)
                    ->where('project_id', $budget->projectId);
            });
        }

        return $query->whereIn('external_account_id', function (Builder $sub) use ($budget, $workspaceId): void {
            $sub->select('bindings.external_account_id')
                ->from('project_integration_bindings as bindings')
                ->join('projects', 'projects.id', '=', 'bindings.project_id')
                ->where('bindings.workspace_id', $workspaceId)
                ->where('projects.client_id', $budget->clientId);
        });
    }

    /**
     * Sum, count, and the two things that decide whether the sum may be used.
     *
     * The count matters because `SUM` over rows that are all null returns null in
     * PostgreSQL while a mix of nulls and zeroes returns zero — so only the count
     * separates "nothing was reported" from "nothing was spent".
     */
    private function aggregate(): string
    {
        return 'sum(spend_minor) as spend_minor, count(spend_minor) as spend_reported, '
            ."string_agg(distinct currency, ',') as currencies, "
            ."string_agg(distinct source, ',') as sources";
    }

    /** @param array<string, mixed>|null $row */
    private function toTotal(?array $row, BudgetView $budget): SpendTotal
    {
        if ($row === null || (int) ($row['spend_reported'] ?? 0) === 0) {
            return SpendTotal::notReported();
        }

        $currencies = $this->split($row['currencies'] ?? null);
        $sources = $this->sources($row['sources'] ?? null);
        $source = MetricSource::weakest($sources);
        $rowCount = (int) ($row['spend_reported'] ?? 0);

        if (count($currencies) > 1) {
            return SpendTotal::unavailable(
                'The accounts in this budget report in '.implode(' and ', $currencies)
                .', so their spend cannot be added together. Split the budget by currency.',
                $source,
                $rowCount,
            );
        }

        $currency = $currencies[0] ?? null;

        if ($currency === null) {
            return SpendTotal::notReported($source);
        }

        if ($currency !== $budget->currency) {
            /*
             * Not a conversion. An exchange rate is a business decision with a date and
             * a source, and inventing one here would put a made-up number next to a
             * figure a client approved.
             */
            return SpendTotal::unavailable(
                sprintf(
                    'This budget is in %s but the spend reported against it is in %s. Converting it here would invent an exchange rate.',
                    $budget->currency,
                    $currency,
                ),
                $source,
                $rowCount,
            );
        }

        if (! $this->combinable($sources)) {
            return SpendTotal::unavailable(
                'This spend mixes sources that cannot be combined — see the source labels on the rows.',
                $source,
                $rowCount,
            );
        }

        return SpendTotal::of((int) ($row['spend_minor'] ?? 0), $source, $currency, $rowCount);
    }

    /**
     * Whether every source present may be added to every other.
     *
     * `MetricSource::mixesWith()` is the domain's own rule, so sandbox spend never joins
     * live spend here even though both are rows in the same table.
     *
     * @param  list<MetricSource>  $sources
     */
    private function combinable(array $sources): bool
    {
        foreach ($sources as $source) {
            foreach ($sources as $other) {
                if (! $source->mixesWith($other)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<string> */
    private function split(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        $parts = [];

        foreach (explode(',', $value) as $part) {
            $trimmed = trim($part);

            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }

        sort($parts);

        return $parts;
    }

    /** @return list<MetricSource> */
    private function sources(mixed $value): array
    {
        $sources = [];

        foreach ($this->split($value) as $raw) {
            $source = MetricSource::tryFrom($raw);

            if ($source instanceof MetricSource) {
                $sources[] = $source;
            }
        }

        return $sources;
    }
}
