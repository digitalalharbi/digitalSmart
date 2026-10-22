<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Contract\AlertSubjects;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class EloquentAlertSubjects implements AlertSubjects
{
    public function ownerOf(string $workspaceId): ?string
    {
        $id = DB::table('workspace_members as m')
            ->join('roles as r', 'r.id', '=', 'm.role_id')
            ->where('m.workspace_id', $workspaceId)
            ->where('m.status', 'active')
            ->where('r.slug', 'owner')
            ->orderBy('m.user_id')
            ->value('m.user_id');

        return $id === null ? null : (string) $id;
    }

    public function campaigns(string $workspaceId, array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('campaigns as c')
            ->leftJoin('projects as p', 'p.id', '=', 'c.project_id')
            ->where('c.workspace_id', $workspaceId)
            ->whereIn('c.id', $campaignIds)
            ->get(['c.id', 'c.name', 'c.project_id', 'p.client_id']) as $row) {
            $out[(string) $row->id] = [
                'label' => (string) $row->name,
                'project_id' => $row->project_id === null ? null : (string) $row->project_id,
                'client_id' => $row->client_id === null ? null : (string) $row->client_id,
            ];
        }

        return $out;
    }

    public function endedBetween(string $workspaceId, DateTimeImmutable $since, DateTimeImmutable $until): array
    {
        $out = [];

        foreach (DB::table('campaigns as c')
            ->leftJoin('projects as p', 'p.id', '=', 'c.project_id')
            ->where('c.workspace_id', $workspaceId)
            ->whereNotNull('c.ends_at')
            ->where('c.ends_at', '>', $since)
            ->where('c.ends_at', '<=', $until)
            ->whereNotIn('c.status', ['removed', 'archived'])
            ->orderBy('c.ends_at')
            ->get(['c.id', 'c.name', 'c.project_id', 'p.client_id', 'c.ends_at']) as $row) {
            $out[] = [
                'id' => (string) $row->id,
                'label' => (string) $row->name,
                'project_id' => $row->project_id === null ? null : (string) $row->project_id,
                'client_id' => $row->client_id === null ? null : (string) $row->client_id,
                'ended_at' => (string) $row->ends_at,
            ];
        }

        return $out;
    }

    public function creatives(string $workspaceId, array $creativeIds): array
    {
        if ($creativeIds === []) {
            return [];
        }

        $out = [];

        /* A creative belongs to no project of its own; the first campaign that runs it is where its audience is. */
        foreach (DB::table('creatives as cr')
            ->leftJoin('ads as a', 'a.creative_id', '=', 'cr.id')
            ->leftJoin('campaigns as c', 'c.id', '=', 'a.campaign_id')
            ->leftJoin('projects as p', 'p.id', '=', 'c.project_id')
            ->where('cr.workspace_id', $workspaceId)
            ->whereIn('cr.id', $creativeIds)
            ->orderBy('cr.id')->orderBy('a.id')
            ->get(['cr.id', 'cr.name', 'c.project_id', 'p.client_id']) as $row) {
            $id = (string) $row->id;

            if (isset($out[$id])) {
                continue;
            }

            $out[$id] = [
                'label' => (string) $row->name,
                'project_id' => $row->project_id === null ? null : (string) $row->project_id,
                'client_id' => $row->client_id === null ? null : (string) $row->client_id,
            ];
        }

        return $out;
    }
}
