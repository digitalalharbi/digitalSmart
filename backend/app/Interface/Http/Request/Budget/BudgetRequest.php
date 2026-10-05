<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Budget;

use App\Application\Budget\DTO\BudgetInput;
use DateTimeImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a budget's shape.
 *
 * Shape only. Whether the amount may still change, whether the period makes sense
 * against an approval, whether the allocations fit — those are product rules and live
 * in the use case, where the budget's current state is known.
 *
 * The amount arrives as a decimal string rather than minor units: an API that takes
 * "1500.00" and an API that takes 150000 look identical in a request body, and the
 * mistake is only visible on a client's invoice.
 */
final class BudgetRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'project_id' => ['sometimes', 'nullable', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            // A decimal amount in the major unit, which is what a person types.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function toInput(): BudgetInput
    {
        return new BudgetInput(
            clientId: $this->string('client_id')->value(),
            name: trim($this->string('name')->value()),
            periodStart: new DateTimeImmutable($this->string('period_start')->value()),
            periodEnd: new DateTimeImmutable($this->string('period_end')->value()),
            amountMinor: $this->amountMinor(),
            currency: strtoupper($this->string('currency')->value()),
            projectId: $this->string('project_id')->value() ?: null,
            notes: $this->filled('notes') ? trim($this->string('notes')->value()) : null,
        );
    }

    /**
     * The amount in minor units, rounded half-up.
     *
     * Rounded rather than truncated, because a typed "1500.005" is a typo and the
     * nearest halala is the kinder reading of it.
     */
    private function amountMinor(): int
    {
        return (int) round(((float) $this->string('amount')->value()) * 100);
    }
}
