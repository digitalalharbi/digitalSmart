<?php

declare(strict_types=1);

namespace App\Application\Advertising\Query;

use App\Application\Advertising\Contract\CampaignControlReadModel;
use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignControlPage;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Advertising\DTO\CampaignVitalsRow;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use App\Domain\Advertising\CampaignHealth;
use App\Domain\Advertising\CampaignHealthPolicy;
use DateTimeImmutable;

/**
 * Every campaign the agency runs, in one place, with a verdict on each.
 *
 * Rule 10's surface and rule 100's first four questions. Three decisions hold it up:
 *
 * **One clock for the whole page.** Read once and passed to every verdict, so a campaign assessed at the
 * top of the list and one at the bottom are judged against the same moment. Reading it per row is how a
 * list comes to disagree with its own summary at midnight.
 *
 * **Health is computed, not stored.** The policy is the only copy of the rules; a `CASE` expression that
 * let the database sort by health would be a second copy, free to drift from the sentence beside it.
 *
 * **The cost of that is a cap, and the cap is published.** Verdicts need a scan, so the read is bounded
 * and ordered by spend — if only part of the agency can be assessed, it should be the part carrying the
 * money. `assessed` and `total` both ship, so an interface can say "the 500 largest of 2,140" instead of
 * implying it has looked at everything.
 */
final readonly class GetCampaignControl
{
    /** Enough to cover a large agency whole, small enough that the scan stays one quick statement. */
    public const int ASSESSMENT_CAP = 2_000;

    /** The window health is read over. Four weeks, so a monthly rhythm appears and a weekly one averages. */
    public const int WINDOW_DAYS = 28;

    private const int PER_PAGE = 50;

    public function __construct(
        private CampaignControlReadModel $campaigns,
        private CampaignHealthPolicy $policy,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(
        CampaignControlFilter $filter,
        ?int $limit = null,
        int $offset = 0,
    ): CampaignControlPage {
        $this->guard->ensure(Permission::AnalyticsView);

        $now = $this->clock->now();
        $to = $now->modify('-1 day');
        $from = $to->modify(sprintf('-%d days', self::WINDOW_DAYS - 1));

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();
        $cap = max(1, min($limit ?? self::PER_PAGE, 200));
        $start = max(0, $offset);

        $vitals = $this->campaigns->vitals(
            $workspaceId,
            $visible,
            $filter,
            $from,
            $to,
            self::ASSESSMENT_CAP,
        );
        $total = $this->campaigns->count($workspaceId, $visible, $filter);

        $rows = array_map(fn (CampaignVitalsRow $row): CampaignControlRow => $this->judge($row, $now), $vitals);

        $counts = array_fill_keys(CampaignControlPage::healthStates(), 0);

        foreach ($rows as $row) {
            $counts[$row->health->value]++;
        }

        if ($filter->health !== []) {
            $wanted = array_map(static fn (CampaignHealth $state): string => $state->value, $filter->health);
            $rows = array_values(array_filter(
                $rows,
                static fn (CampaignControlRow $row): bool => in_array($row->health->value, $wanted, true),
            ));
        }

        /*
         * Worst first, then by spend. A critical campaign spending nothing still outranks a healthy one
         * spending a fortune, because this list is a queue of work rather than a league table.
         */
        usort($rows, static function (CampaignControlRow $a, CampaignControlRow $b): int {
            return [$a->health->rank(), -($a->spendMinor ?? 0)]
                <=> [$b->health->rank(), -($b->spendMinor ?? 0)];
        });

        return new CampaignControlPage(
            rows: array_slice($rows, $start, $cap),
            healthCounts: $counts,
            total: $total,
            assessed: count($vitals),
            isTruncated: count($vitals) < $total,
            from: $from,
            to: $to,
            limit: $cap,
            offset: $start,
        );
    }

    private function judge(CampaignVitalsRow $row, DateTimeImmutable $now): CampaignControlRow
    {
        $verdict = $this->policy->assess($row->vitals, $now);

        return new CampaignControlRow(
            campaignId: $row->campaignId,
            campaignName: $row->campaignName,
            provider: $row->provider,
            externalAccountId: $row->externalAccountId,
            externalAccountName: $row->externalAccountName,
            status: $row->status,
            rawStatus: $row->rawStatus,
            objective: $row->objective,
            health: $verdict->health,
            healthReason: $verdict->reason,
            ownership: $row->ownership,
            projectId: $row->projectId,
            projectName: $row->projectName,
            clientId: $row->clientId,
            clientName: $row->clientName,
            currency: $row->currency,
            budgetMinor: $row->budgetMinor,
            spendMinor: $row->vitals->spendMinor,
            results: $row->vitals->results,
            resultLabel: $row->objective->primaryMetric(),
            costPerResultMinor: $row->vitals->costPerResult(),
            revenueMinor: $row->objective->measuresRevenue() ? $row->vitals->revenueMinor : null,
            roas: $row->vitals->roas(),
            reportedDays: $row->vitals->reportedDays,
            lastSyncedAt: $row->lastSyncedAt,
        );
    }
}
