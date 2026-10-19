<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Advertising\Contract\CampaignHierarchyReadModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EloquentCampaignHierarchyReadModel implements CampaignHierarchyReadModel
{
    public function groups(string $workspaceId, string $campaignId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = DB::table('ad_groups as g')
            ->leftJoin('daily_metrics as m', static function ($join) use ($from, $to): void {
                $join->on('m.entity_id', '=', 'g.id')->where('m.entity_type', 'ad_group')
                    ->whereBetween('m.date', [$from->format('Y-m-d'), $to->format('Y-m-d')]);
            })
            ->where('g.workspace_id', $workspaceId)
            ->where('g.campaign_id', $campaignId)
            ->groupBy('g.id')
            ->orderBy('g.name')
            ->selectRaw("g.id, g.name, g.status, g.budget_mode, g.budget_minor, g.bid_amount_minor, g.currency, g.optimization_goal, g.targeting->>'summary' as audience,
                sum(m.spend_minor) as spend_minor, sum(m.impressions) as impressions, sum(m.clicks) as clicks, sum(m.conversions) as conversions, sum(m.revenue_minor) as revenue_minor")
            ->get();

        return array_values(array_map(static fn (object $r): array => [
            'id' => (string) $r->id,
            'name' => (string) $r->name,
            'status' => (string) $r->status,
            'budget_mode' => (string) $r->budget_mode,
            'budget_minor' => $r->budget_minor === null ? null : (int) $r->budget_minor,
            'bid_minor' => $r->bid_amount_minor === null ? null : (int) $r->bid_amount_minor,
            'currency' => $r->currency,
            'goal' => $r->optimization_goal,
            'audience' => $r->audience,
            'spend_minor' => $r->spend_minor === null ? null : (int) $r->spend_minor,
            'impressions' => $r->impressions === null ? null : (int) $r->impressions,
            'clicks' => $r->clicks === null ? null : (int) $r->clicks,
            'conversions' => $r->conversions === null ? null : (float) $r->conversions,
            'revenue_minor' => $r->revenue_minor === null ? null : (int) $r->revenue_minor,
        ], $rows->all()));
    }

    /**
     * @param  list<string>  $campaignIds
     * @return list<array<string, mixed>>
     */
    public function ads(string $workspaceId, array $campaignIds, DateTimeImmutable $from, DateTimeImmutable $split, DateTimeImmutable $to): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $s = $split->format('Y-m-d');
        $rows = DB::table('ads as a')
            ->leftJoin('creatives as cr', 'cr.id', '=', 'a.creative_id')
            ->leftJoin('daily_metrics as m', static function ($join) use ($from, $to): void {
                $join->on('m.entity_id', '=', 'a.id')->where('m.entity_type', 'ad')
                    ->whereBetween('m.date', [$from->format('Y-m-d'), $to->format('Y-m-d')]);
            })
            ->where('a.workspace_id', $workspaceId)
            ->whereIn('a.campaign_id', $campaignIds)
            ->groupBy('a.id', 'cr.id')
            ->orderBy('a.name')
            ->selectRaw('a.id, a.ad_group_id, a.campaign_id, a.name, a.status, a.provider,
                cr.name as creative_name, cr.format as creative_format, cr.thumbnail_url, cr.title as creative_title,
                sum(m.spend_minor) as spend_minor, sum(m.conversions) as conversions,
                sum(case when m.date >= ? then m.clicks end) as clicks_now,
                sum(case when m.date >= ? then m.impressions end) as impressions_now,
                sum(case when m.date >= ? then m.reach end) as reach_now,
                sum(case when m.date >= ? then m.spend_minor end) as spend_now,
                sum(case when m.date < ? then m.clicks end) as clicks_before,
                sum(case when m.date < ? then m.impressions end) as impressions_before', [$s, $s, $s, $s, $s, $s])
            ->get();

        return array_values(array_map(static fn (object $r): array => [
            'id' => (string) $r->id,
            'ad_group_id' => (string) $r->ad_group_id,
            'campaign_id' => (string) $r->campaign_id,
            'name' => (string) $r->name,
            'status' => (string) $r->status,
            'provider' => (string) $r->provider,
            'creative' => $r->creative_name === null ? null : [
                'name' => (string) $r->creative_name,
                'format' => (string) $r->creative_format,
                'title' => $r->creative_title,
                /* A same-origin path only: a remote preview URL is never rendered inline from here. */
                'thumbnail_url' => is_string($r->thumbnail_url) && str_starts_with($r->thumbnail_url, '/') ? $r->thumbnail_url : null,
            ],
            'spend_minor' => $r->spend_minor === null ? null : (int) $r->spend_minor,
            'spend_now_minor' => $r->spend_now === null ? null : (int) $r->spend_now,
            'conversions' => $r->conversions === null ? null : (float) $r->conversions,
            'clicks_now' => $r->clicks_now === null ? null : (int) $r->clicks_now,
            'impressions_now' => $r->impressions_now === null ? null : (int) $r->impressions_now,
            'reach_now' => $r->reach_now === null ? null : (int) $r->reach_now,
            'clicks_before' => $r->clicks_before === null ? null : (int) $r->clicks_before,
            'impressions_before' => $r->impressions_before === null ? null : (int) $r->impressions_before,
        ], $rows->all()));
    }
}
