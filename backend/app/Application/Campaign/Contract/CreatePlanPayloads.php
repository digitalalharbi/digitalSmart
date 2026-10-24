<?php

declare(strict_types=1);

namespace App\Application\Campaign\Contract;

use App\Application\Campaign\DTO\BlueprintView;
use App\Domain\Integration\Provider;

/**
 * The plan in one platform's own field names, from that platform's documentation, always in its non-delivering state. A draft of
 * what would be sent, never sent from here.
 */
interface CreatePlanPayloads
{
    /**
     * @param  array{objective: string|null, daily_budget_minor: int}  $translation
     * @return array<string, mixed>
     */
    public function draft(Provider $provider, BlueprintView $plan, string $externalAccountId, array $translation): array;
}
