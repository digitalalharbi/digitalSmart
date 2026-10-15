<?php

declare(strict_types=1);

namespace App\Application\Budget\Contract;

/**
 * Facts about every approved budget in one pass.
 *
 * `BudgetSpendReadModel` answers per budget and costs several statements each, which is right for one
 * budget's page and wrong for a finance surface over a hundred of them. This returns raw facts for all
 * of them in a fixed number of statements, and the policy that turns them into risks lives in the query.
 */
interface BudgetControlReadModel
{
    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return list<array<string, mixed>> one array per approved budget: identity, amounts, spend and spend
     *                                    reliability, plus the over-allocation findings for that budget
     */
    public function facts(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        ?string $projectId,
    ): array;
}
