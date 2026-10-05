<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Budget;

use App\Application\Budget\DTO\ForecastRequest as ForecastInput;
use App\Domain\Integration\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a forecast request, in either direction.
 *
 * A budget *or* a target, and exactly one of them: a request carrying both is asking
 * two questions, and answering the one that happens to be checked first is how a reader
 * ends up looking at the wrong number.
 */
final class ForecastRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'providers' => ['sometimes', 'array'],
            'providers.*' => [Rule::in(Provider::values())],
            // Budget → results. A decimal, like every amount in this API.
            'budget' => ['required_without:target_metric', 'nullable', 'numeric', 'min:0.01', 'max:999999999'],
            // Results → budget.
            'target_metric' => ['required_with:target_value', 'nullable', Rule::in(['conversions', 'clicks', 'impressions'])],
            'target_value' => ['required_with:target_metric', 'nullable', 'numeric', 'min:0.01'],
            /*
             * The trailing window the rates come from. Capped at a year: beyond that an
             * account's costs are a different account's costs.
             */
            'window_days' => ['sometimes', 'integer', 'min:7', 'max:365'],
            'client_id' => ['sometimes', 'nullable', 'uuid'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'budget.required_without' => 'Give a budget to estimate results from, or a target to work a budget back from.',
        ];
    }

    public function toInput(): ForecastInput
    {
        /** @var list<string> $providers */
        $providers = array_values(array_filter(
            (array) $this->input('providers', []),
            static fn (mixed $value): bool => is_string($value),
        ));

        $targetMetric = $this->string('target_metric')->value() ?: null;

        return new ForecastInput(
            providers: $providers,
            budgetMinor: $targetMetric === null && $this->filled('budget')
                ? (int) round(((float) $this->string('budget')->value()) * 100)
                : null,
            targetMetric: $targetMetric,
            targetValue: $targetMetric === null ? null : (float) $this->string('target_value')->value(),
            windowDays: (int) ($this->integer('window_days') ?: 90),
            clientId: $this->string('client_id')->value() ?: null,
            projectId: $this->string('project_id')->value() ?: null,
        );
    }
}
