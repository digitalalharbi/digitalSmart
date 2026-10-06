<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Leads\Contract\LeadReadModel;
use App\Application\Leads\DTO\LeadBreakdownRow;
use App\Application\Leads\DTO\LeadSourceRow;
use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Leads\LeadFunnel;
use App\Domain\Leads\LeadSourceKind;
use App\Domain\Leads\LeadStage;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Counting leads.
 *
 * The counts are computed in SQL and the *rates* are not: every ratio is worked out by `LeadFunnel`, in the
 * domain, where the denominators are named and tested. That split is deliberate — a win rate written as a
 * SQL expression is a win rate nobody reviews, and the wrong denominator is the commonest defect in this
 * whole area.
 */
final readonly class EloquentLeadReadModel implements LeadReadModel
{
    public function funnel(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): LeadFunnel {
        /** @var stdClass|null $row */
        $row = $this->scope($workspaceId, $visibleProjectIds, $clientId, $from, $until)
            ->selectRaw(
                'count(*) as total, '
                ."count(*) filter (where l.stage = 'contacted') as contacted, "
                /*
                 * Reached means somebody got through, as the domain defines it: qualified, disqualified, won
                 * or lost. `contacted` is only an attempt, and counting an attempt as contact is how a client
                 * comes to believe their leads were worked.
                 */
                ."count(*) filter (where l.stage in ('qualified','disqualified','won','lost')) as reached, "
                ."count(*) filter (where l.stage = 'qualified') as qualified, "
                ."count(*) filter (where l.stage = 'disqualified') as disqualified, "
                ."count(*) filter (where l.stage = 'won') as won, "
                ."count(*) filter (where l.stage = 'lost') as lost, "
                ."count(*) filter (where l.stage in ('new','contacted','qualified')) as open, "
                ."coalesce(sum(l.value_minor) filter (where l.stage = 'won'), 0) as won_value, "
                ."coalesce(sum(l.value_minor) filter (where l.stage in ('new','contacted','qualified')), 0) as open_value, "
                /*
                 * The currency those sums are in, and how many there were.
                 *
                 * Postgres adds minor units across currencies without complaint, so without this the totals
                 * would be a confident figure made of two different units — 100 halalas plus 100 fils is not
                 * 200 of anything. `min()` names the currency when the count says there is exactly one, and
                 * is discarded otherwise.
                 */
                .'count(distinct l.currency) filter (where l.value_minor is not null) as currency_count, '
                .'min(l.currency) filter (where l.value_minor is not null) as currency'
            )
            ->first();

        /** @var array<string, mixed> $values */
        $values = (array) $row;
        $currency = $this->singleCurrency($values);

        return new LeadFunnel(
            total: (int) ($row->total ?? 0),
            contacted: (int) ($row->contacted ?? 0),
            reached: (int) ($row->reached ?? 0),
            qualified: (int) ($row->qualified ?? 0),
            disqualified: (int) ($row->disqualified ?? 0),
            won: (int) ($row->won ?? 0),
            lost: (int) ($row->lost ?? 0),
            /*
             * Qualified counts as open, because a qualified lead is still in play — it is the stage an agency
             * most wants to see a number for, and treating it as finished would make the win rate's
             * denominator include leads nobody has closed.
             */
            open: (int) ($row->open ?? 0),
            /*
             * Null, not zero, when more than one currency is in play: the counts are still perfectly good —
             * a lead is a lead — so only the money is withheld, and the funnel says why.
             */
            wonValueMinor: $currency === null ? null : (int) ($values['won_value'] ?? 0),
            openValueMinor: $currency === null ? null : (int) ($values['open_value'] ?? 0),
            currency: $currency,
            currencyCount: (int) ($values['currency_count'] ?? 0),
        );
    }

    /** @return list<LeadSourceRow> */
    public function bySource(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): array {
        $rows = $this->scope($workspaceId, $visibleProjectIds, $clientId, $from, $until)
            ->selectRaw(
                'l.source_kind, '
                ."coalesce(nullif(l.source_detail, ''), '') as source_detail, "
                .'count(*) as total, '
                ."count(*) filter (where l.stage = 'qualified') as qualified, "
                ."count(*) filter (where l.stage = 'won') as won, "
                ."count(*) filter (where l.stage in ('won','lost','disqualified')) as closed, "
                ."coalesce(sum(l.value_minor) filter (where l.stage = 'won'), 0) as won_value, "
                .'count(distinct l.currency) filter (where l.value_minor is not null) as currency_count, '
                .'min(l.currency) filter (where l.value_minor is not null) as currency'
            )
            ->groupBy('l.source_kind', 'source_detail')
            ->orderByDesc('total')
            ->get();

        $sources = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $kind = LeadSourceKind::tryFrom((string) ($values['source_kind'] ?? ''));

            if ($kind === null) {
                continue;
            }

            $detail = (string) ($values['source_detail'] ?? '');
            $sourceCurrency = $this->singleCurrency($values);

            $sources[] = new LeadSourceRow(
                sourceKind: $kind,
                sourceDetail: $detail === '' ? null : $detail,
                total: (int) ($values['total'] ?? 0),
                qualified: (int) ($values['qualified'] ?? 0),
                won: (int) ($values['won'] ?? 0),
                closed: (int) ($values['closed'] ?? 0),
                wonValueMinor: $sourceCurrency === null ? null : (int) ($values['won_value'] ?? 0),
                currency: $sourceCurrency,
            );
        }

        return $sources;
    }

    /** @return list<LeadBreakdownRow> */
    public function byCampaign(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): array {
        $naming = $this->campaignNamingEvidence();

        $named = $this->scope($workspaceId, $visibleProjectIds, $clientId, $from, $until)
            ->whereIn('l.attribution_evidence', $naming)
            ->whereNotNull('l.utm_campaign')
            ->selectRaw(
                'l.utm_campaign as campaign, '
                .'count(*) as total, '
                ."count(*) filter (where l.stage = 'qualified') as qualified, "
                ."count(*) filter (where l.stage = 'won') as won, "
                ."count(*) filter (where l.stage in ('won','lost','disqualified')) as closed, "
                // The weakest link in the group: `100 - confidence`, so the highest rank is the weakest.
                .'max('.$this->evidenceRankExpression().') as weakest_rank'
            )
            ->groupBy('campaign')
            ->orderByDesc('total')
            ->get();

        $rows = [];

        foreach ($named as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $rows[] = new LeadBreakdownRow(
                label: (string) ($values['campaign'] ?? ''),
                total: (int) ($values['total'] ?? 0),
                qualified: (int) ($values['qualified'] ?? 0),
                won: (int) ($values['won'] ?? 0),
                closed: (int) ($values['closed'] ?? 0),
                evidence: $this->evidenceFromRank($values['weakest_rank'] ?? null),
            );
        }

        $rest = $this->unattributedRow($workspaceId, $visibleProjectIds, $clientId, $from, $until, $naming);

        if ($rest !== null) {
            $rows[] = $rest;
        }

        return $rows;
    }

    /**
     * The stages that mean somebody got through.
     *
     * Exposed so the SQL above and the domain cannot drift: if a stage is added, this is the one place that
     * decides whether it counts as reached.
     *
     * @return list<string>
     */
    public static function reachedStages(): array
    {
        return array_values(array_map(
            static fn (LeadStage $stage): string => $stage->value,
            array_filter(LeadStage::cases(), static fn (LeadStage $stage): bool => $stage->isReached()),
        ));
    }

    /**
     * Everything no campaign can be named for, as one row.
     *
     * Produced rather than omitted. For leads this bucket is usually enormous — most enquiries arrive with no
     * parameters at all, and a great many arrive by phone — and a breakdown that hid it would make every
     * named campaign look responsible for a far larger share of the pipeline than it is.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @param  list<string>  $naming
     */
    private function unattributedRow(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
        array $naming,
    ): ?LeadBreakdownRow {
        /** @var stdClass|null $row */
        $row = $this->scope($workspaceId, $visibleProjectIds, $clientId, $from, $until)
            ->where(function (Builder $scope) use ($naming): void {
                $scope->whereNotIn('l.attribution_evidence', $naming)
                    ->orWhereNull('l.utm_campaign');
            })
            ->selectRaw(
                'count(*) as total, '
                ."count(*) filter (where l.stage = 'qualified') as qualified, "
                ."count(*) filter (where l.stage = 'won') as won, "
                ."count(*) filter (where l.stage in ('won','lost','disqualified')) as closed"
            )
            ->first();

        $total = (int) ($row->total ?? 0);

        if ($total === 0) {
            return null;
        }

        return new LeadBreakdownRow(
            label: 'Unattributed',
            total: $total,
            qualified: (int) ($row->qualified ?? 0),
            won: (int) ($row->won ?? 0),
            closed: (int) ($row->closed ?? 0),
            evidence: null,
        );
    }

    /**
     * The one currency a group of amounts is in, or null when there is not exactly one.
     *
     * Null is the useful answer in both of the other cases. No valued leads means there is no currency to
     * name and nothing to show; several means the sum is not a figure at all.
     *
     * @param  array<string, mixed>  $values
     */
    private function singleCurrency(array $values): ?string
    {
        $currency = $values['currency'] ?? null;

        return (int) ($values['currency_count'] ?? 0) === 1 && is_string($currency) && $currency !== ''
            ? $currency
            : null;
    }

    /**
     * The base query.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    private function scope(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId,
        DateTimeImmutable $from,
        DateTimeImmutable $until,
    ): Builder {
        $builder = DB::table('leads as l')
            ->where('l.workspace_id', $workspaceId)
            ->where('l.received_at', '>=', $from)
            ->where('l.received_at', '<=', $until);

        if ($clientId !== null) {
            $builder->where('l.client_id', $clientId);
        }

        if ($visibleProjectIds !== null) {
            if ($visibleProjectIds === []) {
                return $builder->whereRaw('1 = 0');
            }

            // The clients of the actor's projects, as everywhere else in this product.
            $builder->whereIn('l.client_id', function (Builder $sub) use ($visibleProjectIds, $workspaceId): void {
                $sub->select('client_id')
                    ->from('projects')
                    ->where('workspace_id', $workspaceId)
                    ->whereIn('id', $visibleProjectIds);
            });
        }

        return $builder;
    }

    /** @return list<string> */
    private function campaignNamingEvidence(): array
    {
        return array_values(array_map(
            static fn (AttributionEvidence $evidence): string => $evidence->value,
            array_filter(
                AttributionEvidence::cases(),
                static fn (AttributionEvidence $evidence): bool => $evidence->identifiesCampaign(),
            ),
        ));
    }

    private function evidenceRankExpression(): string
    {
        $cases = [];

        foreach (AttributionEvidence::cases() as $evidence) {
            $cases[] = sprintf("when l.attribution_evidence = '%s' then %d", $evidence->value, 100 - $evidence->confidence());
        }

        return 'case '.implode(' ', $cases).' else 100 end';
    }

    private function evidenceFromRank(mixed $rank): ?AttributionEvidence
    {
        if (! is_numeric($rank)) {
            return null;
        }

        $confidence = 100 - (int) $rank;

        foreach (AttributionEvidence::cases() as $evidence) {
            if ($evidence->confidence() === $confidence) {
                return $evidence;
            }
        }

        return null;
    }
}
