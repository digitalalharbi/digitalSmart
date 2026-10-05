<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Dashboard;

use App\Application\Dashboard\DTO\DashboardInput;
use App\Application\Dashboard\DTO\WidgetInput;
use App\Domain\Analytics\Dimension;
use App\Domain\Analytics\Metric;
use App\Domain\Dashboard\GridPlacement;
use App\Domain\Dashboard\Visibility;
use App\Domain\Dashboard\WidgetType;
use App\Domain\Integration\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a dashboard and its whole widget set.
 *
 * Every enum-valued field is checked against its enum here, so nothing downstream
 * has to defend against a metric or chart type that does not exist. The *coherence*
 * rules — whether this chart type can show this dimension, whether two widgets
 * overlap — belong to the domain and are enforced in the use case, because they are
 * product rules rather than request shape.
 */
final class DashboardRequest extends FormRequest
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

            // The dashboard's own filters, inherited by every widget that does not
            // override them.
            'filters' => ['sometimes', 'array'],
            'filters.preset' => ['sometimes', 'string', 'max:20'],
            'filters.start' => ['sometimes', 'date_format:Y-m-d'],
            'filters.end' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:filters.start'],
            'filters.compare' => ['sometimes', Rule::in(['none', 'previous_period', 'previous_year'])],
            'filters.providers' => ['sometimes', 'array'],
            'filters.providers.*' => [Rule::in(Provider::values())],
            'filters.project_ids' => ['sometimes', 'array'],
            'filters.project_ids.*' => ['uuid'],
            'filters.client_id' => ['sometimes', 'nullable', 'uuid'],
            'filters.account_ids' => ['sometimes', 'array'],
            'filters.account_ids.*' => ['uuid'],
            'filters.campaign_ids' => ['sometimes', 'array'],
            'filters.campaign_ids.*' => ['uuid'],

            /*
             * Absent means "leave the canvas alone"; an empty array means "the canvas
             * is now empty". Both are valid and they are different instructions, so
             * `sometimes` rather than `nullable` carries the distinction.
             */
            'widgets' => ['sometimes', 'array', 'max:40'],
            'widgets.*.id' => ['sometimes', 'nullable', 'uuid'],
            'widgets.*.title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'widgets.*.type' => ['required', Rule::in(WidgetType::values())],
            'widgets.*.metrics' => ['required', 'array', 'min:1', 'max:6'],
            'widgets.*.metrics.*' => [Rule::in(Metric::values())],
            'widgets.*.dimension' => ['sometimes', 'nullable', Rule::in(Dimension::values())],
            'widgets.*.filter_overrides' => ['sometimes', 'nullable', 'array'],
            'widgets.*.column' => ['required', 'integer', 'min:0', 'max:'.(GridPlacement::COLUMNS - 1)],
            'widgets.*.row' => ['required', 'integer', 'min:0', 'max:60'],
            'widgets.*.width' => ['required', 'integer', 'min:'.GridPlacement::MIN_WIDTH, 'max:'.GridPlacement::COLUMNS],
            'widgets.*.height' => ['required', 'integer', 'min:'.GridPlacement::MIN_HEIGHT, 'max:'.GridPlacement::MAX_HEIGHT],
            'widgets.*.options' => ['sometimes', 'array'],
        ];
    }

    public function toInput(): DashboardInput
    {
        $visibility = Visibility::tryFrom($this->string('visibility', Visibility::Private->value)->value())
            ?? Visibility::Private;

        return new DashboardInput(
            name: trim($this->string('name')->value()),
            description: $this->filled('description') ? trim($this->string('description')->value()) : null,
            visibility: $visibility,
            projectId: $this->string('project_id')->value() ?: null,
            filters: $this->normalisedFilters(),
            isDefault: $this->boolean('is_default'),
            widgets: $this->has('widgets') ? $this->widgets() : null,
        );
    }

    /**
     * The dashboard's filters, kept to the keys the analytics request understands.
     *
     * Anything else is dropped rather than stored: a JSONB column will accept any
     * shape, and a stray key would sit there until something tried to read it.
     *
     * @return array<string, mixed>
     */
    private function normalisedFilters(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $this->input('filters', []);
        $raw = is_array($raw) ? $raw : [];

        $allowed = [
            'preset', 'start', 'end', 'compare', 'providers', 'project_ids',
            'client_id', 'account_ids', 'campaign_ids',
        ];

        return array_filter(
            array_intersect_key($raw, array_flip($allowed)),
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
    }

    /** @return list<WidgetInput> */
    private function widgets(): array
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = $this->input('widgets', []);
        $widgets = [];

        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $type = WidgetType::tryFrom((string) ($entry['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $metrics = [];

            foreach (is_array($entry['metrics'] ?? null) ? $entry['metrics'] : [] as $value) {
                $metric = is_string($value) ? Metric::tryFrom($value) : null;

                if ($metric instanceof Metric) {
                    $metrics[] = $metric;
                }
            }

            $dimensionValue = $entry['dimension'] ?? null;
            $overrides = $entry['filter_overrides'] ?? null;
            $options = $entry['options'] ?? null;

            $widgets[] = new WidgetInput(
                type: $type,
                metrics: $metrics,
                // Constructing this validates the placement against the grid, so an
                // impossible one is refused before anything is written.
                placement: new GridPlacement(
                    column: (int) ($entry['column'] ?? 0),
                    row: (int) ($entry['row'] ?? 0),
                    width: (int) ($entry['width'] ?? 6),
                    height: (int) ($entry['height'] ?? 2),
                ),
                id: is_string($entry['id'] ?? null) ? $entry['id'] : null,
                title: is_string($entry['title'] ?? null) && $entry['title'] !== '' ? $entry['title'] : null,
                dimension: is_string($dimensionValue) ? Dimension::tryFrom($dimensionValue) : null,
                filterOverrides: is_array($overrides) ? $overrides : null,
                options: is_array($options) ? $options : [],
            );
        }

        return $widgets;
    }
}
