<?php

declare(strict_types=1);

namespace App\Application\Budget\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Budget\Contract\BudgetControlReadModel;
use App\Application\Budget\DTO\BudgetControlRow;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use App\Domain\Budget\Pacing;
use App\Domain\Budget\PacingStatus;
use DateTimeImmutable;

/**
 * Every approved budget, with pacing and the risks it carries, in three statements.
 *
 * Rule 37's finance surface and rule 38's central alerts, as one read: finance does not open each project,
 * and there is one place the risk vocabulary is decided.
 *
 * Risks are not exclusive. A budget can be overspending *and* have unallocated money *and* have one
 * platform past its allocation, and a single "status" column would pick one and hide the others.
 */
final readonly class GetBudgetControl
{
    /** Past this share of the approved amount with the period still running, it is close enough to say so. */
    public const float NEAR_LIMIT = 0.90;

    public const array RISKS = ['overspend', 'underpace', 'near_limit', 'unallocated', 'platform_over', 'campaign_over'];

    public function __construct(
        private BudgetControlReadModel $budgets,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    /** @return list<BudgetControlRow> worst first */
    public function __invoke(?string $clientId = null, ?string $projectId = null): array
    {
        $this->guard->ensure(Permission::BudgetsView);

        $today = $this->clock->now();
        $facts = $this->budgets->facts(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $clientId,
            $projectId,
        );

        $rows = array_map(fn (array $f): BudgetControlRow => $this->row($f, $today), $facts);

        usort($rows, static fn (BudgetControlRow $a, BudgetControlRow $b): int => [-count($a->risks), $a->clientName, $a->name]
            <=> [-count($b->risks), $b->clientName, $b->name]);

        return $rows;
    }

    /** @param array<string, mixed> $f */
    private function row(array $f, DateTimeImmutable $today): BudgetControlRow
    {
        $currency = (string) $f['currency'];
        $approved = (int) $f['amount_minor'];
        $allocated = (int) $f['allocated_minor'];
        $start = new DateTimeImmutable((string) $f['period_start']);
        $end = new DateTimeImmutable((string) $f['period_end']);

        [$spent, $reason] = $this->spend($f, $currency);
        $pacing = $spent === null ? null : Pacing::compute($approved, $spent, $start, $end, $today, $currency);

        $risks = [];

        if ($pacing instanceof Pacing) {
            if ($pacing->risksOverspend() || $pacing->status === PacingStatus::Exhausted && $spent > $approved) {
                $risks[] = 'overspend';
            }

            if ($pacing->risksUnderDelivery()) {
                $risks[] = 'underpace';
            }

            if ($approved > 0 && $spent / $approved >= self::NEAR_LIMIT && $today <= $end && ! in_array('overspend', $risks, true)) {
                $risks[] = 'near_limit';
            }
        }

        if ($allocated < $approved && $today <= $end) {
            $risks[] = 'unallocated';
        }

        if ((int) $f['platform_over'] > 0) {
            $risks[] = 'platform_over';
        }

        if ((int) $f['campaign_over'] > 0) {
            $risks[] = 'campaign_over';
        }

        return new BudgetControlRow(
            budgetId: (string) $f['id'],
            name: (string) $f['name'],
            clientId: (string) $f['client_id'],
            clientName: (string) $f['client_name'],
            projectId: is_string($f['project_id'] ?? null) ? $f['project_id'] : null,
            projectName: is_string($f['project_name'] ?? null) ? $f['project_name'] : null,
            currency: $currency,
            approvedMinor: $approved,
            allocatedMinor: $allocated,
            spentMinor: $spent,
            pacing: $pacing,
            unavailableReason: $reason,
            periodStart: $start,
            periodEnd: $end,
            risks: $risks,
        );
    }

    /**
     * Spend, or the reason there is none.
     *
     * Two currencies cannot be added, and sandbox figures do not add to live ones; both arrive as a null
     * with a sentence, never as a total that quietly includes a fixture.
     *
     * @param  array<string, mixed>  $f
     * @return array{0: ?int, 1: ?string}
     */
    private function spend(array $f, string $currency): array
    {
        if ((int) ($f['reported'] ?? 0) === 0) {
            return [null, 'No spend has been reported against this budget yet.'];
        }

        $currencies = array_filter(explode(',', (string) ($f['currencies'] ?? '')));

        if ($currencies !== [$currency]) {
            return [null, 'Spend covers a currency other than the budget’s, so it cannot be totalled.'];
        }

        $sources = array_filter(explode(',', (string) ($f['sources'] ?? '')));

        if (count($sources) > 1 && in_array('sandbox', $sources, true)) {
            return [null, 'Spend mixes sandbox figures with real ones, so it cannot be totalled.'];
        }

        return [(int) $f['spend_minor'], null];
    }
}
