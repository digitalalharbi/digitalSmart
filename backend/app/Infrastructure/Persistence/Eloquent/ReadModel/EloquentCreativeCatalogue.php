<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Analytics\Contract\CreativeCatalogue;
use App\Application\Analytics\DTO\CreativeInfo;
use App\Domain\Advertising\CreativeMedia;
use Illuminate\Support\Facades\DB;

/**
 * Creatives and ads as stored — one query for the creatives, one for their assets, one for where they run.
 */
final readonly class EloquentCreativeCatalogue implements CreativeCatalogue
{
    /** How many campaign names travel with a creative. The count always does. */
    private const int CAMPAIGN_NAMES = 3;

    public function assets(string $workspaceId, array $creativeIds): array
    {
        if ($creativeIds === []) {
            return [];
        }

        $creatives = DB::table('creatives')
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $creativeIds)
            ->get(['id', 'name', 'external_id', 'provider', 'format', 'thumbnail_url']);

        $images = $this->firstImages($creativeIds);
        $placement = $this->placement($workspaceId, $creativeIds);
        $info = [];

        foreach ($creatives as $row) {
            $id = (string) $row->id;
            $where = $placement[$id] ?? ['ads' => 0, 'campaigns' => [], 'campaign_count' => 0];

            $info[$id] = new CreativeInfo(
                key: $id,
                name: self::shown((string) $row->name, (string) $row->external_id, 'Creative'),
                provider: (string) $row->provider,
                format: (string) $row->format,
                media: CreativeMedia::resolve((string) $row->format, $this->string($row->thumbnail_url), $images[$id] ?? null),
                adCount: $where['ads'],
                campaigns: $where['campaigns'],
                campaignCount: $where['campaign_count'],
            );
        }

        return $info;
    }

    public function ads(string $workspaceId, array $adIds): array
    {
        if ($adIds === []) {
            return [];
        }

        $rows = DB::table('ads as a')
            ->join('campaigns as c', 'c.id', '=', 'a.campaign_id')
            ->leftJoin('ad_groups as g', 'g.id', '=', 'a.ad_group_id')
            ->leftJoin('creatives as cr', 'cr.id', '=', 'a.creative_id')
            ->where('a.workspace_id', $workspaceId)
            ->whereIn('a.id', $adIds)
            ->get([
                'a.id', 'a.name', 'a.external_id', 'a.status', 'a.provider', 'a.creative_id',
                'a.ad_group_id', 'a.campaign_id', 'g.name as ad_group_name',
                'c.name as campaign_name', 'cr.format', 'cr.thumbnail_url',
            ]);

        $creativeIds = array_values(array_unique(array_filter(
            $rows->map(static fn ($row): ?string => $row->creative_id === null ? null : (string) $row->creative_id)->all(),
        )));
        $images = $this->firstImages($creativeIds);
        $info = [];

        foreach ($rows as $row) {
            $creative = $row->creative_id === null ? null : (string) $row->creative_id;
            $format = $row->format === null ? 'unknown' : (string) $row->format;

            $info[(string) $row->id] = new CreativeInfo(
                key: (string) $row->id,
                name: self::shown((string) $row->name, (string) $row->external_id, 'Ad'),
                provider: (string) $row->provider,
                format: $format,
                media: CreativeMedia::resolve($format, $this->string($row->thumbnail_url), $creative === null ? null : ($images[$creative] ?? null)),
                adCount: 1,
                campaigns: [(string) $row->campaign_name],
                campaignCount: 1,
                adName: self::shown((string) $row->name, (string) $row->external_id, 'Ad'),
                adStatus: (string) $row->status,
                assetKey: $creative,
                adGroupId: $row->ad_group_id === null ? null : (string) $row->ad_group_id,
                adGroupName: $row->ad_group_name === null ? null : (string) $row->ad_group_name,
                campaignId: (string) $row->campaign_id,
            );
        }

        return $info;
    }

    /**
     * The first image asset of each creative, by position — the fallback when a creative has no thumbnail of its own.
     *
     * @param  list<string>  $creativeIds
     * @return array<string, string>
     */
    private function firstImages(array $creativeIds): array
    {
        if ($creativeIds === []) {
            return [];
        }

        $images = [];

        foreach (DB::table('creative_assets')
            ->whereIn('creative_id', $creativeIds)
            ->where('kind', 'image')
            ->whereNotNull('url')
            ->orderBy('position')
            ->get(['creative_id', 'url']) as $row) {
            $images[(string) $row->creative_id] ??= (string) $row->url;
        }

        return $images;
    }

    /**
     * How many ads run each creative and in which campaigns, in one grouped read.
     *
     * @param  list<string>  $creativeIds
     * @return array<string, array{ads: int, campaigns: list<string>, campaign_count: int}>
     */
    private function placement(string $workspaceId, array $creativeIds): array
    {
        $rows = DB::table('ads as a')
            ->join('campaigns as c', 'c.id', '=', 'a.campaign_id')
            ->where('a.workspace_id', $workspaceId)
            ->whereIn('a.creative_id', $creativeIds)
            ->groupBy('a.creative_id')
            ->selectRaw('a.creative_id, count(*) as ads, jsonb_agg(distinct c.name) as campaigns')
            ->get();

        $placement = [];

        foreach ($rows as $row) {
            /** @var list<string> $names */
            $names = json_decode((string) $row->campaigns, true) ?: [];
            sort($names);

            $placement[(string) $row->creative_id] = [
                'ads' => (int) $row->ads,
                'campaigns' => array_slice($names, 0, self::CAMPAIGN_NAMES),
                'campaign_count' => count($names),
            ];
        }

        return $placement;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * A name a client may read. Some platforms name an unnamed thing after its own id ("Ad 40110003"); that is the
     * provider's identifier, and nothing a client surface prints.
     */
    private static function shown(string $name, string $externalId, string $fallback): string
    {
        $clean = $externalId === '' ? $name : trim(str_replace($externalId, '', $name));

        return $clean === '' ? $fallback : $clean;
    }
}
