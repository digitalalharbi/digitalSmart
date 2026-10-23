<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\LinkEconomicsReadModel;
use App\Domain\Tracking\Cost\LinkSpend;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Spend and revenue for one link.
 *
 * Spend is the spend of the entity the link is bound to, at that entity's own level — the campaign row for a campaign
 * link, never the sum of its ads. It is credited to the link only when nothing else competes for it: another link on the
 * same entity, or (for a campaign or ad group) a link on something inside it, would make the figure shared. Sharing is
 * refused by default and divided only when the caller asks for the one allocation offered, and then says so.
 */
final readonly class EloquentLinkEconomicsReadModel implements LinkEconomicsReadModel
{
    public function spend(string $workspaceId, string $linkId, DateTimeImmutable $from, DateTimeImmutable $to, bool $allocateByClicks): LinkSpend
    {
        $link = $this->link($workspaceId, $linkId);

        if (! $link instanceof stdClass) {
            return LinkSpend::unavailable('The link was not found.');
        }

        [$level, $entityId, $column] = match (true) {
            is_string($link->ad_id) => ['ad', (string) $link->ad_id, 'ad_id'],
            is_string($link->ad_group_id) => ['ad_group', (string) $link->ad_group_id, 'ad_group_id'],
            is_string($link->campaign_id) => ['campaign', (string) $link->campaign_id, 'campaign_id'],
            default => [null, null, null],
        };

        if ($level === null || $entityId === null || $column === null) {
            return LinkSpend::unavailable('This link is not bound to a campaign, ad group or ad, so no spend can be credited to it.');
        }

        $others = DB::table('tracking_links')
            ->where('workspace_id', $workspaceId)->where('id', '!=', $linkId)->where('status', '!=', 'archived')
            ->where($column, $entityId);

        $inside = (clone $others);
        $same = (clone $others);

        /* Links bound deeper than this one, inside the same entity, make its spend shared with them. */
        $deeper = match ($level) {
            'campaign' => $inside->whereNotNull('ad_group_id')->count(),
            'ad_group' => $inside->whereNotNull('ad_id')->count(),
            default => 0,
        };
        $peers = match ($level) {
            'campaign' => $same->whereNull('ad_group_id')->count(),
            'ad_group' => $same->whereNull('ad_id')->count(),
            default => $same->count(),
        };
        $label = ['campaign' => 'campaign', 'ad_group' => 'ad group', 'ad' => 'ad'][$level];

        if ($deeper > 0) {
            return LinkSpend::unavailable(sprintf('Other links are bound inside this %s, so its spend is not exclusive to this link.', $label), $level);
        }

        if ($peers > 0 && ! $allocateByClicks) {
            return LinkSpend::unavailable(sprintf('%d other link%s bound to the same %s; its spend cannot be assigned to one of them. Allocation by tracked clicks can be asked for.', $peers, $peers === 1 ? ' is' : 's are', $label), $level);
        }

        $rows = DB::table('daily_metrics')
            ->where('workspace_id', $workspaceId)->where('entity_type', $level)->where('entity_id', $entityId)
            ->where('date', '>=', $from->format('Y-m-d'))->where('date', '<', $to->format('Y-m-d'))
            ->whereNotNull('spend_minor')
            ->selectRaw('currency, sum(spend_minor) as spend, count(*) as days')
            ->groupBy('currency')->get();

        if ($rows->isEmpty()) {
            return LinkSpend::unavailable(sprintf('The %s reported no spend in this period.', $label), $level);
        }

        if ($rows->count() > 1) {
            return LinkSpend::unavailable(sprintf('The %s reported spend in more than one currency, which is not converted.', $label), $level);
        }

        $row = $rows->first();

        $spend = (int) $row->spend;
        $currency = (string) $row->currency;
        $days = (int) $row->days;

        if ($peers === 0) {
            return LinkSpend::direct($spend, $currency, $level, $days);
        }

        $clicks = $this->clicks($workspaceId, array_values(array_merge([$linkId], $same->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all())), $from, $to);
        $total = array_sum($clicks);

        if ($total === 0) {
            return LinkSpend::unavailable('No tracked clicks to divide the spend by.', $level);
        }

        $share = ($clicks[$linkId] ?? 0) / $total;
        $periodDays = max(1, (int) $from->diff($to)->days);

        return LinkSpend::allocated((int) round($spend * $share), $currency, $level, $days, [
            'method' => 'tracked_clicks',
            'share' => round($share, 4),
            'coverage' => round(min(1, $days / $periodDays), 2),
            'confidence' => 'low',
        ]);
    }

    public function revenue(string $workspaceId, string $linkId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return [
            'lead_value' => $this->leadValue($workspaceId, $linkId, $from, $to),
            'store_attributed' => $this->storeAttributed($workspaceId, $linkId, $from, $to),
        ];
    }

    /** @return array{minor: ?int, currency: ?string, count: ?int, reason: ?string, evidence: string} */
    private function leadValue(string $workspaceId, string $linkId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = DB::select(
            "SELECT l.currency, sum(l.value_minor) AS total, count(*) AS n
             FROM leads l
             WHERE l.workspace_id = ? AND l.stage = 'won' AND l.value_minor IS NOT NULL AND l.closed_at >= ? AND l.closed_at < ?
               AND l.id IN (SELECT f.lead_id FROM form_submissions f JOIN tracking_sessions s ON s.id = f.session_id
                            WHERE s.tracking_link_id = ? AND f.lead_id IS NOT NULL AND f.traffic_class = 'valid')
             GROUP BY l.currency",
            [$workspaceId, $from->format('Y-m-d H:i:sP'), $to->format('Y-m-d H:i:sP'), $linkId],
        );

        $none = ['minor' => null, 'currency' => null, 'count' => null, 'evidence' => 'recorded_by_person'];

        if ($rows === []) {
            return [...$none, 'reason' => 'No won lead from this link has a value recorded.'];
        }

        if (count($rows) > 1) {
            return [...$none, 'reason' => 'Won values are in more than one currency and are not converted.'];
        }

        return ['minor' => (int) $rows[0]->total, 'currency' => (string) $rows[0]->currency, 'count' => (int) $rows[0]->n, 'reason' => null, 'evidence' => 'recorded_by_person'];
    }

    /** @return array{minor: ?int, currency: ?string, count: ?int, reason: ?string, evidence: string} */
    private function storeAttributed(string $workspaceId, string $linkId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $none = ['minor' => null, 'currency' => null, 'count' => null, 'evidence' => 'last_recorded'];
        $link = $this->link($workspaceId, $linkId);
        $tag = $link instanceof stdClass && is_string($link->utm_campaign) ? $link->utm_campaign : null;

        if ($tag === null || $tag === '') {
            return [...$none, 'reason' => 'This link carries no campaign tag, so no store order can be matched to it.'];
        }

        $sharing = DB::table('tracking_links')->where('workspace_id', $workspaceId)->where('client_id', $link?->client_id)
            ->where('utm_campaign', $tag)->where('status', '!=', 'archived')->count();

        if ($sharing > 1) {
            return [...$none, 'reason' => 'Several links share this campaign tag, so an order cannot be matched to just one.'];
        }

        $rows = DB::select(
            "SELECT o.currency, sum(o.total_minor) AS total, count(*) AS n
             FROM store_orders o JOIN stores st ON st.id = o.store_id
             WHERE o.workspace_id = ? AND st.client_id = ? AND o.utm_campaign = ? AND o.payment_state IN ('paid','partially_refunded')
               AND o.placed_at >= ? AND o.placed_at < ?
             GROUP BY o.currency",
            [$workspaceId, $link?->client_id, $tag, $from->format('Y-m-d H:i:sP'), $to->format('Y-m-d H:i:sP')],
        );

        if ($rows === []) {
            return [...$none, 'reason' => 'No paid store order carries this link’s campaign tag in the period.'];
        }

        if (count($rows) > 1) {
            return [...$none, 'reason' => 'Orders are in more than one currency and are not converted.'];
        }

        return ['minor' => (int) $rows[0]->total, 'currency' => (string) $rows[0]->currency, 'count' => (int) $rows[0]->n, 'reason' => null, 'evidence' => 'last_recorded'];
    }

    /**
     * @param  list<string>  $linkIds
     * @return array<string, int>
     */
    private function clicks(string $workspaceId, array $linkIds, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $out = array_fill_keys($linkIds, 0);

        foreach (DB::table('tracking_sessions')
            ->where('workspace_id', $workspaceId)->whereIn('tracking_link_id', $linkIds)->where('traffic_class', 'valid')
            ->where('started_at', '>=', $from->format('Y-m-d H:i:sP'))->where('started_at', '<', $to->format('Y-m-d H:i:sP'))
            ->selectRaw('tracking_link_id, count(*) as n')->groupBy('tracking_link_id')->get() as $row) {
            $out[(string) $row->tracking_link_id] = (int) $row->n;
        }

        return $out;
    }

    private function link(string $workspaceId, string $linkId): ?stdClass
    {
        $row = DB::table('tracking_links')->where('workspace_id', $workspaceId)->where('id', $linkId)->first();

        return $row instanceof stdClass ? $row : null;
    }
}
