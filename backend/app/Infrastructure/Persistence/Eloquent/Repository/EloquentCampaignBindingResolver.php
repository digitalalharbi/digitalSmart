<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\CampaignBindingResolver;
use App\Application\Tracking\DTO\CampaignBinding;
use App\Application\Tracking\Exception\TrackingRefused;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Resolves a link's place in the campaign hierarchy from the tables the sync already fills.
 *
 * Authority runs from the most specific entity upward: an ad knows its ad group and campaign, an ad group knows its
 * campaign, a campaign knows its account and platform. Whatever the caller typed beside it must agree, or the link is
 * refused rather than quietly corrected.
 */
final readonly class EloquentCampaignBindingResolver implements CampaignBindingResolver
{
    public function resolve(
        string $workspaceId,
        string $projectId,
        ?string $platform,
        ?string $externalAccountId,
        ?string $campaignId,
        ?string $adGroupId,
        ?string $adId,
        ?string $creativeId,
    ): ?CampaignBinding {
        if ($externalAccountId !== null) {
            $this->assertAccountBound($workspaceId, $projectId, $externalAccountId);
        }

        if ($campaignId === null && $adGroupId === null && $adId === null) {
            if ($creativeId !== null) {
                throw new TrackingRefused('A creative is chosen through its ad.');
            }

            return null;
        }

        $ad = null;
        $adGroup = null;

        if ($adId !== null) {
            $ad = $this->row('ads', $workspaceId, $adId, 'Ad');
            $adGroupId = $this->agree($adGroupId, (string) $ad->ad_group_id, 'This ad does not belong to the chosen ad group.');
            $campaignId = $this->agree($campaignId, (string) $ad->campaign_id, 'This ad does not belong to the chosen campaign.');
        }

        if ($adGroupId !== null) {
            $adGroup = $this->row('ad_groups', $workspaceId, $adGroupId, 'Ad group');
            $campaignId = $this->agree($campaignId, (string) $adGroup->campaign_id, 'This ad group does not belong to the chosen campaign.');
        }

        if ($campaignId === null) {
            throw new TrackingRefused('Choose a campaign.');
        }

        $campaign = $this->row('campaigns', $workspaceId, $campaignId, 'Campaign');
        $accountId = (string) $campaign->external_account_id;

        if ($externalAccountId !== null && $externalAccountId !== $accountId) {
            throw new TrackingRefused('This campaign belongs to a different ad account.');
        }

        $this->assertAccountBound($workspaceId, $projectId, $accountId);

        if (is_string($campaign->project_id ?? null) && $campaign->project_id !== $projectId) {
            throw new TrackingRefused('This campaign is assigned to another project.');
        }

        $provider = (string) $campaign->provider;

        if ($platform !== null && $platform !== $provider) {
            throw new TrackingRefused('This campaign runs on a different platform.');
        }

        $creativeName = null;

        if ($creativeId !== null) {
            if (! $ad instanceof stdClass || ! is_string($ad->creative_id ?? null) || $ad->creative_id !== $creativeId) {
                throw new TrackingRefused('This creative is not the one this ad runs.');
            }

            $creativeName = $this->name(DB::table('creatives')->where('workspace_id', $workspaceId)->where('id', $creativeId)->value('name'));
        } elseif ($ad instanceof stdClass && is_string($ad->creative_id ?? null)) {
            /* The ad's own creative is evidence, so it is taken rather than asked for twice. */
            $creativeId = $ad->creative_id;
            $creativeName = $this->name(DB::table('creatives')->where('workspace_id', $workspaceId)->where('id', $creativeId)->value('name'));
        }

        return new CampaignBinding(
            $provider, $accountId, $campaignId, (string) $campaign->name,
            $adGroup instanceof stdClass ? (string) $adGroup->id : null,
            $adGroup instanceof stdClass ? (string) $adGroup->name : null,
            $ad instanceof stdClass ? (string) $ad->id : null,
            $ad instanceof stdClass ? (string) $ad->name : null,
            $creativeId, $creativeName,
        );
    }

    public function options(string $workspaceId, string $projectId, string $level, ?string $parentId, ?string $search): array
    {
        $like = $search === null || $search === '' ? null : '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($search)).'%';
        $rows = match ($level) {
            'accounts' => DB::table('project_integration_bindings as b')
                ->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
                ->where('b.workspace_id', $workspaceId)->where('b.project_id', $projectId)
                ->select('a.id', 'a.name', 'a.provider as detail')->orderBy('a.name'),
            'campaigns' => DB::table('campaigns as c')->where('c.workspace_id', $workspaceId)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('project_integration_bindings as b')
                    ->whereColumn('b.external_account_id', 'c.external_account_id')->where('b.project_id', $projectId))
                ->where(fn ($q) => $q->whereNull('c.project_id')->orWhere('c.project_id', $projectId))
                ->when($parentId !== null, fn ($q) => $q->where('c.external_account_id', $parentId))
                ->select('c.id', 'c.name', 'c.status as detail')->orderBy('c.name'),
            'ad_groups' => DB::table('ad_groups as g')->where('g.workspace_id', $workspaceId)
                ->where('g.campaign_id', $parentId ?? '00000000-0000-0000-0000-000000000000')
                ->select('g.id', 'g.name', 'g.status as detail')->orderBy('g.name'),
            'ads' => DB::table('ads as d')->where('d.workspace_id', $workspaceId)
                ->where('d.ad_group_id', $parentId ?? '00000000-0000-0000-0000-000000000000')
                ->select('d.id', 'd.name', 'd.status as detail')->orderBy('d.name'),
            default => throw new TrackingRefused('Unknown level.'),
        };

        if ($like !== null) {
            $column = match ($level) {
                'accounts' => 'a.name', 'campaigns' => 'c.name', 'ad_groups' => 'g.name', default => 'd.name',
            };
            $rows->whereRaw("lower({$column}) like ?", [$like]);
        }

        $out = [];

        foreach ($rows->limit(100)->get() as $row) {
            $out[] = ['id' => (string) $row->id, 'name' => (string) $row->name, 'detail' => is_string($row->detail) ? $row->detail : null];
        }

        return $out;
    }

    private function assertAccountBound(string $workspaceId, string $projectId, string $accountId): void
    {
        $bound = DB::table('project_integration_bindings')
            ->where('workspace_id', $workspaceId)->where('project_id', $projectId)->where('external_account_id', $accountId)->exists();

        if (! $bound) {
            throw new TrackingRefused('This ad account is not connected to the project.');
        }
    }

    private function row(string $table, string $workspaceId, string $id, string $what): stdClass
    {
        $row = DB::table($table)->where('workspace_id', $workspaceId)->where('id', $id)->first();

        return $row instanceof stdClass ? $row : throw new TrackingRefused($what.' not found.');
    }

    private function agree(?string $typed, string $known, string $message): string
    {
        if ($typed !== null && $typed !== $known) {
            throw new TrackingRefused($message);
        }

        return $known;
    }

    private function name(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
