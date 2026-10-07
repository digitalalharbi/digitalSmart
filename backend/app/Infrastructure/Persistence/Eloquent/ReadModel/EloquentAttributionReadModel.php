<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Attribution\Contract\AttributionReadModel;
use App\Application\Attribution\DTO\AttributedCampaignRow;
use App\Application\Attribution\DTO\AttributionQuery;
use App\Application\Attribution\DTO\AttributionResult;
use App\Domain\Attribution\AttributionCoverage;
use App\Domain\Attribution\AttributionModel;
use App\Domain\Attribution\CampaignLink;
use App\Domain\Commerce\AttributionEvidence;
use App\Domain\Commerce\PaymentState;
use DateTimeImmutable;
use Exception;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Attribution, computed from the orders themselves.
 *
 * **Every model runs over one scan of the same rows, and groups in PHP.** That is a deliberate
 * trade. Aggregating each model in its own SQL statement would be faster and would mean a
 * disagreement between two models could come from the two queries differing rather than from the
 * models differing — which is precisely the thing this phase's comparison surface is supposed to
 * measure. One scan, one grouping path, so a disagreement is a real one.
 *
 * The cost is a row-level read bounded by the requested date range. That is fine at agency scale and
 * is the first thing to replace if it stops being: a materialised attribution table, rebuilt when
 * orders sync, would keep the same grouping code and feed it from a smaller set.
 *
 * Nothing here infers a link. An order with no marketing information on it is counted in the
 * coverage denominator and credited to nobody, which is the whole discipline of
 * `AttributionEvidence` carried forward into the models built on it.
 *
 * @phpstan-type OrderRow array{
 *     placed_at: DateTimeImmutable|null,
 *     currency: string,
 *     evidence: string,
 *     campaign: string|null,
 *     coupon: string|null,
 *     medium: string|null,
 *     phone_key: string|null,
 *     email_key: string|null,
 *     net: int,
 * }
 * @phpstan-type HistoryRow array{
 *     campaign: string|null,
 *     evidence: string,
 *     occurred_at: DateTimeImmutable|null,
 * }
 * @phpstan-type SpendRow array{spend: int, provider: string, currency: string|null, source: string}
 */
final readonly class EloquentAttributionReadModel implements AttributionReadModel
{
    /** Money has arrived. The same two states the store analytics count, so the totals reconcile. */
    private const array REVENUE_STATES = [
        PaymentState::Paid->value,
        PaymentState::PartiallyRefunded->value,
    ];

    /**
     * Mediums the platforms use for traffic somebody paid for.
     *
     * Matched case-insensitively against `utm_medium`. Deliberately a list rather than "anything that
     * is not organic": a medium nobody recognises is not evidence of paid traffic, and treating it as
     * such would quietly move organic revenue into a paid report.
     */
    private const array PAID_MEDIUMS = [
        'cpc', 'ppc', 'cpm', 'cpv', 'paid', 'paidsocial', 'paid_social', 'paid-social',
        'display', 'retargeting', 'remarketing', 'affiliate',
    ];

    public function attribute(AttributionQuery $query): AttributionResult
    {
        $orders = $this->orders($query);
        $history = $query->model->needsPerson() ? $this->history($query) : [];
        $spend = $this->spendByCampaignName($query);

        $groups = [];
        $conversions = 0;
        $revenue = 0;
        $attributedConversions = 0;
        $attributedRevenue = 0;
        $campaignNamed = 0;
        $currencies = [];
        $weakestRank = -1;
        $confidenceWeight = 0;
        $confidenceTotal = 0;

        foreach ($orders as $order) {
            $conversions += 1;
            $revenue += $order['net'];
            $currencies[$order['currency']] = true;

            $credit = $this->credit($query->model, $order, $history, $query->lookbackDays);

            if ($credit === null) {
                continue;
            }

            $attributedConversions += 1;
            $attributedRevenue += $order['net'];
            $weakestRank = max($weakestRank, 100 - $credit['evidence']->confidence());

            // Revenue-weighted, because a reader is judging the money rather than the row count.
            $confidenceWeight += $order['net'];
            $confidenceTotal += $order['net'] * $credit['evidence']->confidence();

            if ($credit['label'] !== null) {
                $campaignNamed += 1;
            }

            $key = $credit['label'] ?? '#'.$credit['link']->value;

            $groups[$key] ??= [
                'label' => $credit['label'],
                'link' => $credit['link'],
                'conversions' => 0,
                'revenue' => 0,
                'rank' => -1,
            ];

            $groups[$key]['conversions'] += 1;
            $groups[$key]['revenue'] += $order['net'];
            $groups[$key]['rank'] = max($groups[$key]['rank'], 100 - $credit['evidence']->confidence());
        }

        /*
         * One currency or none. A sum of minor units across currencies is not a smaller or larger
         * figure, it is not a figure — the same rule the Lead Center applies to its totals.
         */
        $currency = count($currencies) === 1 ? (string) array_key_first($currencies) : null;

        return new AttributionResult(
            coverage: new AttributionCoverage(
                conversions: $conversions,
                attributedConversions: $attributedConversions,
                campaignNamedConversions: $campaignNamed,
                revenueMinor: $currency === null ? null : $revenue,
                attributedRevenueMinor: $currency === null ? null : $attributedRevenue,
                currency: $currency,
                weakestEvidence: $this->evidenceFromRank($weakestRank),
                confidence: $confidenceWeight > 0 ? (int) round($confidenceTotal / $confidenceWeight) : null,
            ),
            rows: $this->rows($groups, $spend, $currency, $query->limit),
            unmatchedSpendMinor: $this->unmatchedSpend($groups, $spend),
            spendCurrency: $this->spendCurrency($spend),
        );
    }

    /**
     * Which campaign this order's revenue is credited to under one model, or null for none.
     *
     * The only place a model's definition becomes an answer. Each branch is short on purpose: the
     * arithmetic of attribution is trivial, and all the difficulty is in being exact about what is
     * being claimed.
     *
     * @param  OrderRow  $order
     * @param  array<string, list<HistoryRow>>  $history
     * @return array{label: string|null, link: CampaignLink, evidence: AttributionEvidence}|null
     */
    private function credit(
        AttributionModel $model,
        array $order,
        array $history,
        int $lookbackDays,
    ): ?array {
        $evidence = AttributionEvidence::tryFrom($order['evidence']) ?? AttributionEvidence::None;

        if ($evidence === AttributionEvidence::None) {
            return null;
        }

        if ($model === AttributionModel::PaidLastClick && ! $this->isPaid($order, $evidence)) {
            /*
             * Not unattributed — attributed to something this model does not count. It still raises
             * the coverage denominator, so a paid report shows how much of the period it leaves out.
             */
            return null;
        }

        if ($model === AttributionModel::FirstRecorded) {
            $earlier = $this->earliestConversion($order, $history, $lookbackDays);

            if ($earlier !== null) {
                return $this->link(
                    $earlier['campaign'],
                    AttributionEvidence::tryFrom($earlier['evidence']) ?? AttributionEvidence::None,
                );
            }

            /*
             * No earlier conversion for this person within the window, so the order *is* their first
             * recorded one and the model credits what it carried. Falling through rather than
             * dropping it: a first-time customer is the commonest case, and a model that reported
             * nothing for them would understate every campaign that wins new business.
             */
        }

        return $this->link($this->campaignName($order), $evidence);
    }

    /**
     * Whether this order's link is one somebody paid for.
     *
     * A platform click identifier always is — the platform minted it on a click it charged for. A UTM
     * medium is checked against the list of mediums platforms use for paid traffic, and nothing else
     * counts: a bare `utm_source=meta` says the traffic came from Meta, not that it was bought.
     *
     * @param  OrderRow  $order
     */
    private function isPaid(array $order, AttributionEvidence $evidence): bool
    {
        if ($evidence === AttributionEvidence::ClickIdentifier) {
            return true;
        }

        $medium = $order['medium'] === null ? '' : strtolower(trim($order['medium']));

        return $medium !== '' && in_array($medium, self::PAID_MEDIUMS, true);
    }

    /**
     * The earliest conversion recorded for this order's customer, inside the lookback window.
     *
     * Matched on a normalised phone number or e-mail address through the keys Phase 16 added, and on
     * nothing else — no names, no addresses. Two people called Mohammed Al-Harbi are two people, and
     * a wrong merge here moves one customer's revenue onto another customer's campaign.
     *
     * @param  OrderRow  $order
     * @param  array<string, list<HistoryRow>>  $history
     * @return HistoryRow|null
     */
    private function earliestConversion(array $order, array $history, int $lookbackDays): ?array
    {
        $placed = $order['placed_at'];

        if ($placed === null) {
            return null;
        }

        $floor = $placed->modify('-'.$lookbackDays.' days');
        $earliest = null;
        $earliestAt = null;

        foreach ([$order['phone_key'], $order['email_key']] as $contactKey) {
            if ($contactKey === null || $contactKey === '') {
                continue;
            }

            foreach ($history[$contactKey] ?? [] as $candidate) {
                $at = $candidate['occurred_at'];

                if ($at === null || $at > $placed || $at < $floor) {
                    continue;
                }

                if ($earliestAt === null || $at < $earliestAt) {
                    $earliest = $candidate;
                    $earliestAt = $at;
                }
            }
        }

        return $earliest;
    }

    /**
     * How firmly a credited label ties to an ad campaign.
     *
     * A click identifier with no campaign parameter is genuinely attributed and genuinely unplaceable
     * on a campaign row: resolving one needs the platform's click-level API, which this product does
     * not read. Saying so is more useful than a row labelled "(unknown)".
     *
     * @return array{label: string|null, link: CampaignLink, evidence: AttributionEvidence}
     */
    private function link(?string $label, AttributionEvidence $evidence): array
    {
        if ($label !== null && trim($label) !== '') {
            return ['label' => trim($label), 'link' => CampaignLink::ExactName, 'evidence' => $evidence];
        }

        return [
            'label' => null,
            'link' => $evidence === AttributionEvidence::ClickIdentifier
                ? CampaignLink::ClickNotResolved
                : CampaignLink::ChannelOnly,
            'evidence' => $evidence,
        ];
    }

    /**
     * The campaign an order names, falling back to the coupon it carried.
     *
     * The same expression the store analytics use, for a reason that matters more than tidiness: two
     * surfaces disagreeing about how many campaigns a client has is worse than either being wrong.
     *
     * @param  OrderRow  $order
     */
    private function campaignName(array $order): ?string
    {
        foreach ([$order['campaign'], $order['coupon']] as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * The period's orders, one row each.
     *
     * @return list<OrderRow>
     */
    private function orders(AttributionQuery $query): array
    {
        $rows = $this->scope($query)
            ->leftJoin('store_customers as c', 'c.id', '=', 'o.store_customer_id')
            ->whereIn('o.payment_state', self::REVENUE_STATES)
            ->get([
                'o.placed_at',
                'o.currency',
                'o.attribution_evidence as evidence',
                'o.utm_campaign as campaign',
                'o.coupon_code as coupon',
                'o.utm_medium as medium',
                'c.phone_key',
                'c.email_key',
                DB::raw('(o.total_minor - coalesce(o.refunded_minor, 0)) as net'),
            ]);

        $orders = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $orders[] = [
                'placed_at' => $this->date($values['placed_at'] ?? null),
                'currency' => (string) ($values['currency'] ?? ''),
                'evidence' => (string) ($values['evidence'] ?? ''),
                'campaign' => $this->text($values['campaign'] ?? null),
                'coupon' => $this->text($values['coupon'] ?? null),
                'medium' => $this->text($values['medium'] ?? null),
                'phone_key' => $this->text($values['phone_key'] ?? null),
                'email_key' => $this->text($values['email_key'] ?? null),
                'net' => (int) ($values['net'] ?? 0),
            ];
        }

        return $orders;
    }

    /**
     * Every attributed conversion a person has, indexed by contact key.
     *
     * Leads and orders both, because a person's history of conversions spans the two and a model that
     * read only orders would credit the order that followed an enquiry rather than the enquiry — which
     * is the opposite of what a first-recorded model is for.
     *
     * The window is applied per order rather than here, because the floor depends on when each order
     * was placed.
     *
     * @return array<string, list<HistoryRow>>
     */
    private function history(AttributionQuery $query): array
    {
        $earliest = $query->from->modify('-'.$query->lookbackDays.' days');

        $leads = DB::table('leads as l')
            ->where('l.workspace_id', $query->workspaceId)
            ->where('l.received_at', '>=', $earliest)
            ->where('l.received_at', '<=', $query->until)
            ->whereNotNull('l.utm_campaign')
            ->when(
                $query->clientId !== null,
                fn (Builder $scope): Builder => $scope->where('l.client_id', $query->clientId),
            )
            ->get([
                'l.utm_campaign as campaign',
                'l.attribution_evidence as evidence',
                'l.received_at as occurred_at',
                'l.phone_key',
                'l.email_key',
            ]);

        $orders = DB::table('store_orders as o')
            ->join('stores as s', 's.id', '=', 'o.store_id')
            ->leftJoin('store_customers as c', 'c.id', '=', 'o.store_customer_id')
            ->where('o.workspace_id', $query->workspaceId)
            ->where('o.revenue_source', $query->revenueSource->value)
            ->where('o.placed_at', '>=', $earliest)
            ->where('o.placed_at', '<=', $query->until)
            ->whereIn('o.payment_state', self::REVENUE_STATES)
            ->where(function (Builder $scope): void {
                $scope->whereNotNull('o.utm_campaign')->orWhereNotNull('o.coupon_code');
            })
            ->when(
                $query->clientId !== null,
                fn (Builder $scope): Builder => $scope->where('s.client_id', $query->clientId),
            )
            ->get([
                DB::raw("coalesce(nullif(o.utm_campaign, ''), nullif(o.coupon_code, '')) as campaign"),
                'o.attribution_evidence as evidence',
                'o.placed_at as occurred_at',
                'c.phone_key',
                'c.email_key',
            ]);

        $history = [];

        foreach ([$leads, $orders] as $set) {
            foreach ($set as $row) {
                /** @var array<string, mixed> $values */
                $values = (array) $row;

                $entry = [
                    'campaign' => $this->text($values['campaign'] ?? null),
                    'evidence' => (string) ($values['evidence'] ?? ''),
                    'occurred_at' => $this->date($values['occurred_at'] ?? null),
                ];

                foreach (['phone_key', 'email_key'] as $key) {
                    $contactKey = $this->text($values[$key] ?? null);

                    if ($contactKey !== null) {
                        $history[$contactKey][] = $entry;
                    }
                }
            }
        }

        return $history;
    }

    /**
     * Ad spend per campaign name, for the rows that can carry it.
     *
     * Keyed by the campaign's name folded to lower case with surrounding space removed — and matched
     * on nothing looser than that. `autumn-range` and `autumn-range-v2` are two campaigns, and a
     * report that merged them would attach a budget decision to the wrong one.
     *
     * @return array<string, SpendRow>
     */
    private function spendByCampaignName(AttributionQuery $query): array
    {
        $rows = DB::table('daily_metrics as m')
            ->join('campaigns as k', 'k.id', '=', 'm.entity_id')
            ->where('m.workspace_id', $query->workspaceId)
            ->where('m.entity_type', 'campaign')
            ->whereBetween('m.date', [$query->from->format('Y-m-d'), $query->until->format('Y-m-d')])
            ->groupBy(DB::raw('lower(trim(k.name))'), 'm.provider')
            ->get([
                DB::raw('lower(trim(k.name)) as name'),
                'm.provider',
                DB::raw('coalesce(sum(m.spend_minor), 0) as spend'),
                DB::raw('count(distinct m.currency) as currencies'),
                DB::raw('min(m.currency) as currency'),
                /*
                 * The weakest honesty label among the days that make up this figure, alphabetically —
                 * which puts `sandbox` ahead of `synced`. Crude, and right for the one case that
                 * matters: a campaign with any sandbox day must not be presented as measured.
                 */
                DB::raw('min(m.source) as source'),
            ]);

        $spend = [];

        foreach ($rows as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;
            $name = (string) ($values['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $currencies = (int) ($values['currencies'] ?? 0);

            /*
             * A campaign billed in two currencies across the period has no single spend figure, so it
             * gets none — the row still appears, with its revenue, and the empty cell is honest.
             */
            $spend[$name] = [
                'spend' => (int) ($values['spend'] ?? 0),
                'provider' => (string) ($values['provider'] ?? ''),
                'currency' => $currencies === 1 ? (string) ($values['currency'] ?? '') : null,
                'source' => (string) ($values['source'] ?? 'synced'),
            ];
        }

        return $spend;
    }

    /**
     * The grouped credits as rows, largest first, with spend attached where a name matched.
     *
     * @param  array<string, array{label: string|null, link: CampaignLink, conversions: int, revenue: int, rank: int}>  $groups
     * @param  array<string, SpendRow>  $spend
     * @return list<AttributedCampaignRow>
     */
    private function rows(array $groups, array $spend, ?string $currency, int $limit): array
    {
        uasort($groups, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        $rows = [];

        foreach (array_slice($groups, 0, $limit, true) as $group) {
            $label = $group['label'];
            $link = $group['link'];
            $matched = $label === null ? null : ($spend[strtolower(trim($label))] ?? null);

            if ($label !== null && $matched === null) {
                // Named a campaign nobody has connected, which is a finding rather than a blank.
                $link = CampaignLink::NoMatchingCampaign;
            }

            $rows[] = new AttributedCampaignRow(
                label: $label ?? $link->label(),
                link: $link,
                provider: $matched['provider'] ?? null,
                conversions: $group['conversions'],
                revenueMinor: $currency === null ? null : $group['revenue'],
                currency: $currency,
                evidence: $this->evidenceFromRank($group['rank']),
                /*
                 * Spend only where the currencies agree. Dividing riyals of revenue by dirhams of
                 * spend produces a ROAS that looks ordinary and means nothing.
                 */
                spendMinor: $matched !== null && $matched['currency'] === $currency ? $matched['spend'] : null,
                spendSource: $matched['source'] ?? null,
            );
        }

        return $rows;
    }

    /**
     * Spend on campaigns that no attributed revenue was matched to.
     *
     * Reported rather than dropped, because it is half the question. A client who spent 400,000 and
     * can see 180,000 of it against revenue needs to know the other 220,000 went somewhere the orders
     * never mentioned — that is a tagging problem with a name, not a gap in the report.
     *
     * @param  array<string, array{label: string|null, link: CampaignLink, conversions: int, revenue: int, rank: int}>  $groups
     * @param  array<string, SpendRow>  $spend
     */
    private function unmatchedSpend(array $groups, array $spend): ?int
    {
        if ($spend === []) {
            return null;
        }

        $credited = [];

        foreach ($groups as $group) {
            if ($group['label'] !== null) {
                $credited[strtolower(trim($group['label']))] = true;
            }
        }

        $total = 0;

        foreach ($spend as $name => $row) {
            if (! isset($credited[$name])) {
                $total += $row['spend'];
            }
        }

        return $total;
    }

    /** @param array<string, SpendRow> $spend */
    private function spendCurrency(array $spend): ?string
    {
        $currencies = [];

        foreach ($spend as $row) {
            if ($row['currency'] !== null) {
                $currencies[$row['currency']] = true;
            }
        }

        return count($currencies) === 1 ? (string) array_key_first($currencies) : null;
    }

    /**
     * The order scope.
     *
     * The same rules the store analytics apply — one revenue authority per answer, and a store is
     * visible through its client's projects — written the same way so the two surfaces cannot
     * disagree about whose money this is.
     */
    private function scope(AttributionQuery $query): Builder
    {
        $builder = DB::table('store_orders as o')
            ->join('stores as s', 's.id', '=', 'o.store_id')
            ->where('o.workspace_id', $query->workspaceId)
            ->where('o.revenue_source', $query->revenueSource->value)
            ->where('o.placed_at', '>=', $query->from)
            ->where('o.placed_at', '<=', $query->until);

        if ($query->clientId !== null) {
            $builder->where('s.client_id', $query->clientId);
        }

        if ($query->visibleProjectIds !== null) {
            if ($query->visibleProjectIds === []) {
                return $builder->whereRaw('1 = 0');
            }

            $builder->whereIn('s.client_id', function (Builder $sub) use ($query): void {
                $sub->select('client_id')
                    ->from('projects')
                    ->where('workspace_id', $query->workspaceId)
                    ->whereIn('id', $query->visibleProjectIds ?? []);
            });
        }

        return $builder;
    }

    /** The evidence a rank came from. Ranks are `100 - confidence`, so the highest is the weakest. */
    private function evidenceFromRank(int $rank): AttributionEvidence
    {
        foreach (AttributionEvidence::cases() as $evidence) {
            if (100 - $evidence->confidence() === $rank) {
                return $evidence;
            }
        }

        return AttributionEvidence::None;
    }

    /** A non-empty trimmed string, or null. Keeps the row shapes honest about what a column holds. */
    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                return null;
            }
        }

        return null;
    }
}
