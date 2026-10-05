<?php

declare(strict_types=1);

namespace App\Interface\Http\Request\Budget;

use App\Application\Budget\DTO\AllocationInput;
use App\Domain\Budget\AllocationLevel;
use App\Domain\Integration\Provider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a whole allocation tree.
 *
 * Three levels deep and no deeper, which is the governance hierarchy itself: platform,
 * campaign, ad group. The nesting is the request's shape rather than a parent id per
 * row, so a campaign allocation cannot arrive claiming the budget as its parent.
 *
 * The sums — whether the parts fit inside the whole — are the use case's business, and
 * so is whether a child names the same platform as its parent. Validation here is
 * about what the fields are, not about whether the plan adds up.
 */
final class AllocationsRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'allocations' => ['present', 'array', 'max:40'],
            ...$this->levelRules('allocations.*'),
            'allocations.*.children' => ['sometimes', 'array', 'max:60'],
            ...$this->levelRules('allocations.*.children.*'),
            'allocations.*.children.*.children' => ['sometimes', 'array', 'max:60'],
            ...$this->levelRules('allocations.*.children.*.children.*'),
        ];
    }

    /** @return list<AllocationInput> */
    public function toInput(): array
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = $this->input('allocations', []);

        return $this->nodes(is_array($raw) ? $raw : []);
    }

    /**
     * The rules that apply at every level of the tree.
     *
     * @return array<string, list<mixed>>
     */
    private function levelRules(string $prefix): array
    {
        return [
            $prefix.'.level' => ['required', Rule::in(AllocationLevel::values())],
            $prefix.'.provider' => ['required', Rule::in(Provider::values())],
            $prefix.'.label' => ['required', 'string', 'max:160'],
            $prefix.'.amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            $prefix.'.external_account_id' => ['sometimes', 'nullable', 'uuid'],
            $prefix.'.campaign_id' => ['sometimes', 'nullable', 'uuid'],
            $prefix.'.ad_group_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<AllocationInput>
     */
    private function nodes(array $raw): array
    {
        $nodes = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $level = AllocationLevel::tryFrom((string) ($entry['level'] ?? ''));
            $provider = Provider::tryFrom((string) ($entry['provider'] ?? ''));

            if ($level === null || $provider === null) {
                continue;
            }

            /** @var list<array<string, mixed>> $children */
            $children = is_array($entry['children'] ?? null) ? $entry['children'] : [];

            $nodes[] = new AllocationInput(
                level: $level,
                provider: $provider,
                label: trim((string) ($entry['label'] ?? '')),
                amountMinor: (int) round(((float) ($entry['amount'] ?? 0)) * 100),
                externalAccountId: $this->uuid($entry['external_account_id'] ?? null),
                campaignId: $this->uuid($entry['campaign_id'] ?? null),
                adGroupId: $this->uuid($entry['ad_group_id'] ?? null),
                children: $this->nodes($children),
            );
        }

        return $nodes;
    }

    private function uuid(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
