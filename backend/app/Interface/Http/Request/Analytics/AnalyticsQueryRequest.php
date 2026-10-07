<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Analytics;

use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\AnalyticsRequest;
use App\Application\Shared\Contract\Clock;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Integration\Provider;
use App\Domain\Shared\ValueObject\DateRange;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates and assembles one analytics request.
 *
 * Every enum-valued parameter is validated against its enum rather than passed
 * through, so a caller cannot reach the read model with a metric or dimension
 * that does not exist — and the read model never has to interpolate a caller's
 * string into SQL.
 */
final class AnalyticsQueryRequest extends FormRequest
{
    /** The widest range this endpoint will answer in one request. */
    public const MAX_RANGE_DAYS = 400;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'start' => ['sometimes', 'date_format:Y-m-d'],
            'end' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:start'],
            // A named window, so the common cases need no date arithmetic client-side.
            'preset' => ['sometimes', Rule::in(['7d', '14d', '28d', '30d', '90d', 'this_month', 'last_month'])],
            'compare' => ['sometimes', Rule::in(['none', 'previous_period', 'previous_year'])],

            'metrics' => ['sometimes', 'array', 'max:40'],
            'metrics.*' => [Rule::in(Metric::values())],

            'dimension' => ['sometimes', 'nullable', Rule::in(Dimension::values())],
            'order_by' => ['sometimes', 'nullable', Rule::in(Metric::values())],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],

            'providers' => ['sometimes', 'array'],
            'providers.*' => [Rule::in(Provider::values())],
            'account_ids' => ['sometimes', 'array'],
            'account_ids.*' => ['uuid'],
            'campaign_ids' => ['sometimes', 'array'],
            'campaign_ids.*' => ['uuid'],
            'ad_group_ids' => ['sometimes', 'array'],
            'ad_group_ids.*' => ['uuid'],
            'ad_ids' => ['sometimes', 'array'],
            'ad_ids.*' => ['uuid'],
            'project_ids' => ['sometimes', 'array'],
            'project_ids.*' => ['uuid'],
            'client_id' => ['sometimes', 'nullable', 'uuid'],

            // Which level of stored rows to read, when the caller wants one
            // other than the level its filters imply.
            'level' => ['sometimes', Rule::in(['account', 'campaign', 'ad_group', 'ad'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'end.after_or_equal' => 'The end date cannot fall before the start date.',
        ];
    }

    public function toAnalyticsRequest(Clock $clock): AnalyticsRequest
    {
        $range = $this->range($clock);

        return new AnalyticsRequest(
            range: $range,
            filters: $this->filters(),
            metrics: $this->metrics(),
            compareTo: $this->comparison($range),
            dimension: $this->dimension(),
            orderBy: $this->orderBy(),
            limit: $this->integer('limit', 50),
        );
    }

    /** The level a caller explicitly asked for, if any. */
    public function level(): ?AdEntityType
    {
        $value = $this->string('level')->value();

        return $value === '' ? null : AdEntityType::tryFrom($value);
    }

    private function range(Clock $clock): DateRange
    {
        $today = $clock->now();

        if ($this->filled('start') && $this->filled('end')) {
            return new DateRange(
                new DateTimeImmutable($this->string('start')->value()),
                new DateTimeImmutable($this->string('end')->value()),
            );
        }

        return match ($this->string('preset', '28d')->value()) {
            '7d' => DateRange::lastDays(7, $today),
            '14d' => DateRange::lastDays(14, $today),
            '30d' => DateRange::lastDays(30, $today),
            '90d' => DateRange::lastDays(90, $today),
            'this_month' => new DateRange(
                $today->modify('first day of this month'),
                // Yesterday, or the first of the month on the first — never a
                // partial today mixed in with whole days.
                max($today->modify('-1 day'), $today->modify('first day of this month')),
            ),
            'last_month' => new DateRange(
                $today->modify('first day of last month'),
                $today->modify('last day of last month'),
            ),
            default => DateRange::lastDays(28, $today),
        };
    }

    private function comparison(DateRange $range): ?DateRange
    {
        return match ($this->string('compare', 'none')->value()) {
            'previous_period' => $range->previousPeriod(),
            'previous_year' => $range->previousYear(),
            default => null,
        };
    }

    /** @return list<Metric> */
    private function metrics(): array
    {
        /** @var list<string> $requested */
        $requested = $this->input('metrics', []);
        $requested = is_array($requested) ? $requested : [];

        $metrics = [];

        foreach ($requested as $value) {
            $metric = is_string($value) ? Metric::tryFrom($value) : null;

            if ($metric instanceof Metric) {
                $metrics[] = $metric;
            }
        }

        if ($metrics !== []) {
            return array_values(array_unique($metrics, SORT_REGULAR));
        }

        // The default scorecard, so a caller asking for nothing in particular
        // still gets a coherent set rather than an empty one.
        return [
            Metric::Spend, Metric::Revenue, Metric::Roas, Metric::Conversions,
            Metric::CostPerConversion, Metric::Impressions, Metric::Clicks,
            Metric::Ctr, Metric::Cpc, Metric::Cpm,
        ];
    }

    private function filters(): AnalyticsFilters
    {
        return new AnalyticsFilters(
            providers: $this->uuidList('providers'),
            externalAccountIds: $this->uuidList('account_ids'),
            campaignIds: $this->uuidList('campaign_ids'),
            adGroupIds: $this->uuidList('ad_group_ids'),
            adIds: $this->uuidList('ad_ids'),
            projectIds: $this->uuidList('project_ids'),
            clientId: $this->string('client_id')->value() ?: null,
        );
    }

    private function dimension(): ?Dimension
    {
        $value = $this->string('dimension')->value();

        return $value === '' ? null : Dimension::tryFrom($value);
    }

    private function orderBy(): ?Metric
    {
        $value = $this->string('order_by')->value();

        return $value === '' ? null : Metric::tryFrom($value);
    }

    /** @return list<string> */
    private function uuidList(string $key): array
    {
        $values = $this->input($key, []);
        $list = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && $value !== '') {
                $list[] = $value;
            }
        }

        return array_values(array_unique($list));
    }
}
