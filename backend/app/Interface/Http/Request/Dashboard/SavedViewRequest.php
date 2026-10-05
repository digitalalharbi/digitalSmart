<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Dashboard;

use App\Application\Analytics\DTO\AnalyticsFilters;
use App\Application\Analytics\DTO\AnalyticsRequest;
use App\Application\Analytics\DTO\DateRange;
use App\Application\Dashboard\DTO\SavedViewInput;
use App\Application\Shared\Contract\Clock;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Dashboard\Visibility;
use App\Domain\Integration\Provider;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a saved analytics view.
 *
 * The query is rebuilt into a real `AnalyticsRequest` rather than stored as whatever
 * JSON arrived. That costs a little ceremony and buys the thing that matters: a view
 * saved today still parses in six months, because it went through the same
 * validation the live query does.
 */
final class SavedViewRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'visibility' => ['sometimes', Rule::in(Visibility::values())],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'is_default' => ['sometimes', 'boolean'],

            'query' => ['required', 'array'],
            'query.preset' => ['sometimes', Rule::in(['7d', '14d', '28d', '30d', '90d', 'this_month', 'last_month'])],
            'query.start' => ['sometimes', 'date_format:Y-m-d'],
            'query.end' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:query.start'],
            'query.compare' => ['sometimes', Rule::in(['none', 'previous_period', 'previous_year'])],
            'query.metrics' => ['sometimes', 'array', 'max:40'],
            'query.metrics.*' => [Rule::in(Metric::values())],
            'query.dimension' => ['sometimes', 'nullable', Rule::in(Dimension::values())],
            'query.order_by' => ['sometimes', 'nullable', Rule::in(Metric::values())],
            'query.limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'query.providers' => ['sometimes', 'array'],
            'query.providers.*' => [Rule::in(Provider::values())],
            'query.account_ids' => ['sometimes', 'array'],
            'query.account_ids.*' => ['uuid'],
            'query.campaign_ids' => ['sometimes', 'array'],
            'query.campaign_ids.*' => ['uuid'],
            'query.ad_group_ids' => ['sometimes', 'array'],
            'query.ad_group_ids.*' => ['uuid'],
            'query.ad_ids' => ['sometimes', 'array'],
            'query.ad_ids.*' => ['uuid'],
            'query.project_ids' => ['sometimes', 'array'],
            'query.project_ids.*' => ['uuid'],
            'query.client_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    public function toInput(Clock $clock): SavedViewInput
    {
        $visibility = Visibility::tryFrom($this->string('visibility', Visibility::Private->value)->value())
            ?? Visibility::Private;

        return new SavedViewInput(
            name: trim($this->string('name')->value()),
            query: $this->toAnalyticsRequest($clock),
            visibility: $visibility,
            projectId: $this->string('project_id')->value() ?: null,
            description: $this->filled('description') ? trim($this->string('description')->value()) : null,
            isDefault: $this->boolean('is_default'),
        );
    }

    private function toAnalyticsRequest(Clock $clock): AnalyticsRequest
    {
        $range = $this->range($clock);

        return new AnalyticsRequest(
            range: $range,
            filters: new AnalyticsFilters(
                providers: $this->list('query.providers'),
                externalAccountIds: $this->list('query.account_ids'),
                campaignIds: $this->list('query.campaign_ids'),
                adGroupIds: $this->list('query.ad_group_ids'),
                adIds: $this->list('query.ad_ids'),
                projectIds: $this->list('query.project_ids'),
                clientId: $this->string('query.client_id')->value() ?: null,
            ),
            metrics: $this->metrics(),
            compareTo: $this->comparison($range),
            // Laravel's own Request::enum, which returns null for an absent or
            // unrecognised value — exactly the behaviour wanted here.
            dimension: $this->enum('query.dimension', Dimension::class),
            orderBy: $this->enum('query.order_by', Metric::class),
            limit: $this->integer('query.limit', 50),
        );
    }

    private function range(Clock $clock): DateRange
    {
        if ($this->filled('query.start') && $this->filled('query.end')) {
            return new DateRange(
                new DateTimeImmutable($this->string('query.start')->value()),
                new DateTimeImmutable($this->string('query.end')->value()),
            );
        }

        /*
         * A preset is stored rather than the dates it resolved to. A view called
         * "last 28 days" should mean that whenever it is opened — freezing the dates
         * would turn it into a snapshot of one month, which is a different feature.
         */
        $today = $clock->now();

        return match ($this->string('query.preset', '28d')->value()) {
            '7d' => DateRange::lastDays(7, $today),
            '14d' => DateRange::lastDays(14, $today),
            '30d' => DateRange::lastDays(30, $today),
            '90d' => DateRange::lastDays(90, $today),
            default => DateRange::lastDays(28, $today),
        };
    }

    private function comparison(DateRange $range): ?DateRange
    {
        return match ($this->string('query.compare', 'none')->value()) {
            'previous_period' => $range->previousPeriod(),
            'previous_year' => $range->previousYear(),
            default => null,
        };
    }

    /** @return list<Metric> */
    private function metrics(): array
    {
        $metrics = [];

        foreach ($this->list('query.metrics') as $value) {
            $metric = Metric::tryFrom($value);

            if ($metric instanceof Metric) {
                $metrics[] = $metric;
            }
        }

        return $metrics === [] ? [Metric::Spend, Metric::Roas, Metric::Conversions, Metric::CostPerConversion] : $metrics;
    }

    /** @return list<string> */
    private function list(string $key): array
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
