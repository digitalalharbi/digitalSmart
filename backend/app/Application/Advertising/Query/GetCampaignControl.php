<?php

declare(strict_types=1);

namespace App\Application\Advertising\Query;

use App\Application\Advertising\Contract\CampaignControlReadModel;
use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\DTO\CampaignControlPage;
use App\Application\Advertising\DTO\CampaignControlRow;
use App\Application\Advertising\DTO\CampaignVitalsRow;
use App\Application\Advertising\DTO\EntityControlRow;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use App\Domain\Advertising\AdEntityType;
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

    /** The periods a reader may choose. Anything else is the default, so a typo cannot ask for a year. */
    public const array WINDOWS = [7, 14, 28, 30, 56, 90];

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
        ?int $windowDays = null,
    ): CampaignControlPage {
        $assessment = $this->assessAll($filter, $windowDays);
        $rows = $assessment['rows'];
        $now = $assessment['now'];
        $to = $assessment['to'];
        $from = $assessment['from'];
        $total = $assessment['total'];
        $vitals = $assessment['rows'];
        $workspaceId = $this->actor->workspaceId()->value;
        $cap = max(1, min($limit ?? self::PER_PAGE, 200));
        $start = max(0, $offset);

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
        $key = match ($filter->sort) {
            'spend' => static fn (CampaignControlRow $r): array => [-($r->spendMinor ?? -1), $r->campaignName],
            'name' => static fn (CampaignControlRow $r): array => [mb_strtolower($r->campaignName)],
            /* Nulls last whichever way it is sorted: "no figure" is not the cheapest or the dearest result. */
            'cost' => static fn (CampaignControlRow $r): array => [$r->costPerResultMinor === null ? 1 : 0, $r->costPerResultMinor ?? 0.0, $r->campaignName],
            'roas' => static fn (CampaignControlRow $r): array => [$r->roas === null ? 1 : 0, -($r->roas ?? 0.0), $r->campaignName],
            default => static fn (CampaignControlRow $r): array => [$r->health->rank(), -($r->spendMinor ?? 0), $r->campaignName],
        };

        usort($rows, static fn (CampaignControlRow $a, CampaignControlRow $b): int => $key($a) <=> $key($b));

        if ($filter->descending) {
            $rows = array_reverse($rows);
        }

        $matched = count($rows);
        $activeCount = count(array_filter(
            $rows,
            static fn (CampaignControlRow $r): bool => $r->status->isSpending(),
        ));
        $slice = array_slice($rows, $start, $cap);

        /*
         * The headline spend and its trend describe everything that matched, not the page — summed in one
         * grouped statement, per currency.
         */
        $matchedIds = array_map(static fn (CampaignControlRow $r): string => $r->campaignId, $rows);
        $spendTrend = $this->campaigns->spendByDay($workspaceId, $matchedIds, $from, $to);
        $spendByCurrency = [];

        foreach ($rows as $row) {
            if ($row->spendMinor !== null && $row->currency !== null) {
                $spendByCurrency[$row->currency] = ($spendByCurrency[$row->currency] ?? 0) + $row->spendMinor;
            }
        }

        /* One statement for the whole page, not one per card. Only the page is drawn, so only the page is read. */
        $trends = $this->campaigns->trends(
            $workspaceId,
            array_map(static fn (CampaignControlRow $r): string => $r->campaignId, $slice),
            $now->modify('-14 days'),
            $to,
        );

        $slice = array_map(
            static fn (CampaignControlRow $r): CampaignControlRow => $r->withTrend($trends[$r->campaignId] ?? []),
            $slice,
        );

        return new CampaignControlPage(
            rows: $slice,
            matched: $matched,
            activeCount: $activeCount,
            healthCounts: $counts,
            total: $total,
            assessed: count($vitals),
            isTruncated: count($vitals) < $total,
            from: $from,
            to: $to,
            limit: $cap,
            offset: $start,
            spendByCurrency: $spendByCurrency,
            spendTrend: $spendTrend,
        );
    }

    /**
     * Every campaign the reader may see, judged — the shared basis of the control center, the portfolio
     * and the attention queue.
     *
     * Public because those three must agree about a campaign, and the only way to guarantee that is for
     * there to be one place a verdict is produced. Capped at `ASSESSMENT_CAP`, with `total` beside it.
     *
     * @return array{rows: list<CampaignControlRow>, total: int, now: DateTimeImmutable, from: DateTimeImmutable, to: DateTimeImmutable}
     */
    public function assessAll(CampaignControlFilter $filter, ?int $windowDays = null): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $now = $this->clock->now();
        $to = $now->modify('-1 day');
        $from = $to->modify(sprintf('-%d days', self::window($windowDays) - 1));

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        $vitals = $this->campaigns->vitals($workspaceId, $visible, $filter, $from, $to, self::ASSESSMENT_CAP);
        $total = $this->campaigns->count($workspaceId, $visible, $filter);

        return [
            'rows' => array_map(fn (CampaignVitalsRow $row): CampaignControlRow => $this->judge($row, $now), $vitals),
            'total' => $total,
            'now' => $now,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Ad groups or ads under the campaigns the filter selects, each judged on its own rows over the same window the
     * campaigns are — what an ad-group or ad rule reads.
     *
     * @param  list<string>  $adGroupIds
     * @param  list<string>  $adIds
     * @return list<EntityControlRow>
     */
    public function assessEntities(AdEntityType $level, CampaignControlFilter $filter, array $adGroupIds = [], array $adIds = [], ?int $windowDays = null): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $to = $this->clock->now()->modify('-1 day');
        $from = $to->modify(sprintf('-%d days', self::window($windowDays) - 1));

        return $this->campaigns->entities(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $filter,
            $level,
            $adGroupIds,
            $adIds,
            $from,
            $to,
            self::ASSESSMENT_CAP,
        );
    }

    public static function window(?int $days): int
    {
        return $days !== null && in_array($days, self::WINDOWS, true) ? $days : self::WINDOW_DAYS;
    }

    /** Average spend on a reporting day against a *daily* budget. Any other mode has no daily line to read against. */
    private function utilisation(CampaignVitalsRow $row): ?float
    {
        $spend = $row->vitals->spendMinor;

        if ($row->budgetMode !== 'daily' || $row->budgetMinor === null || $row->budgetMinor <= 0
            || $spend === null || $row->vitals->reportedDays === 0) {
            return null;
        }

        return ($spend / $row->vitals->reportedDays) / $row->budgetMinor;
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
            budgetUtilisation: $this->utilisation($row),
            healthReasonCode: $verdict->code,
            healthReasonParams: $verdict->params,
            endsAt: $row->endsAt,
            startedAt: $row->startedAt,
            impressions: $row->vitals->impressions,
            clicks: $row->vitals->clicks,
        );
    }
}
