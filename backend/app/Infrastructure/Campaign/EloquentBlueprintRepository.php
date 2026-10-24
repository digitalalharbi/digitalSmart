<?php

declare(strict_types=1);

namespace App\Infrastructure\Campaign;

use App\Application\Campaign\Contract\BlueprintRepository;
use App\Application\Campaign\DTO\BlueprintInput;
use App\Application\Campaign\DTO\BlueprintView;
use App\Domain\Advertising\Blueprint\BlueprintStatus;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class EloquentBlueprintRepository implements BlueprintRepository
{
    public function clientOf(string $workspaceId, string $projectId): ?string
    {
        if (! Str::isUuid($projectId)) {
            return null;
        }

        $client = DB::table('projects')->where('workspace_id', $workspaceId)->where('id', $projectId)->whereNull('deleted_at')->value('client_id');

        return $client === null ? null : (string) $client;
    }

    public function boundAccounts(string $workspaceId, string $projectId, array $ids): array
    {
        $ids = array_values(array_filter($ids, Str::isUuid(...)));

        if ($ids === []) {
            return [];
        }

        return DB::table('project_integration_bindings as b')
            ->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->where('b.workspace_id', $workspaceId)->where('a.workspace_id', $workspaceId)
            ->where('b.project_id', $projectId)->whereIn('a.id', $ids)
            ->pluck('a.provider', 'a.id')
            ->map(static fn (mixed $p): string => (string) $p)->all();
    }

    public function trackingLinkInProject(string $workspaceId, string $projectId, string $linkId): bool
    {
        return Str::isUuid($linkId) && DB::table('tracking_links')->where('workspace_id', $workspaceId)->where('project_id', $projectId)->where('id', $linkId)->exists();
    }

    public function conversionForClient(string $workspaceId, string $clientId, string $eventId): bool
    {
        return Str::isUuid($eventId) && DB::table('event_definitions')->where('workspace_id', $workspaceId)->where('client_id', $clientId)->where('id', $eventId)->exists();
    }

    public function creativesForClient(string $workspaceId, string $clientId, array $ids): array
    {
        $ids = array_values(array_filter($ids, Str::isUuid(...)));

        if ($ids === []) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            DB::table('creative_requests')->where('workspace_id', $workspaceId)->where('client_id', $clientId)->whereIn('id', $ids)->pluck('id')->all(),
        ));
    }

    public function create(string $id, string $workspaceId, string $clientId, BlueprintInput $input, array $targets, string $createdBy): void
    {
        DB::transaction(function () use ($id, $workspaceId, $clientId, $input, $targets, $createdBy): void {
            DB::table('campaign_blueprints')->insert([
                ...$this->columns($input),
                'id' => $id,
                'workspace_id' => $workspaceId,
                'client_id' => $clientId,
                'project_id' => $input->projectId,
                'status' => BlueprintStatus::Draft->value,
                'version' => 1,
                'created_by' => $createdBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->replaceChildren($id, $targets, $input->creativeRequestIds);
        });
    }

    public function update(string $workspaceId, string $id, BlueprintInput $input, array $targets): void
    {
        DB::transaction(function () use ($workspaceId, $id, $input, $targets): void {
            DB::table('campaign_blueprints')->where('workspace_id', $workspaceId)->where('id', $id)->update([
                ...$this->columns($input),
                'status' => BlueprintStatus::Draft->value,
                'status_reason' => null,
                'submitted_by' => null,
                'submitted_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'approved_fingerprint' => null,
                'approved_version' => null,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
            $this->replaceChildren($id, $targets, $input->creativeRequestIds);
        });
    }

    public function setStatus(string $workspaceId, string $id, BlueprintStatus $status, ?string $reason, ?string $byUserId): void
    {
        $values = ['status' => $status->value, 'status_reason' => $reason, 'updated_at' => now()];

        if ($status === BlueprintStatus::ReadyForReview) {
            $values += ['submitted_by' => $byUserId, 'submitted_at' => now()];
        }

        if ($status === BlueprintStatus::Draft) {
            $values += ['submitted_by' => null, 'submitted_at' => null, 'approved_by' => null, 'approved_at' => null, 'approved_fingerprint' => null, 'approved_version' => null];
        }

        DB::table('campaign_blueprints')->where('workspace_id', $workspaceId)->where('id', $id)->update($values);
    }

    public function approve(string $workspaceId, string $id, string $byUserId, string $fingerprint, int $version): void
    {
        DB::table('campaign_blueprints')->where('workspace_id', $workspaceId)->where('id', $id)->update([
            'status' => BlueprintStatus::Approved->value,
            'status_reason' => null,
            'approved_by' => $byUserId,
            'approved_at' => now(),
            'approved_fingerprint' => $fingerprint,
            'approved_version' => $version,
            'updated_at' => now(),
        ]);
    }

    public function withdrawApproval(string $workspaceId, string $id, string $reason): void
    {
        DB::table('campaign_blueprints')->where('workspace_id', $workspaceId)->where('id', $id)->update([
            'status' => BlueprintStatus::ReadyForReview->value,
            'status_reason' => $reason,
            'approved_by' => null,
            'approved_at' => null,
            'approved_fingerprint' => null,
            'approved_version' => null,
            'updated_at' => now(),
        ]);
    }

    public function approval(string $workspaceId, string $id): array
    {
        $row = DB::table('campaign_blueprints')->where('workspace_id', $workspaceId)->where('id', $id)->first(['approved_fingerprint', 'approved_version']);

        return [
            'fingerprint' => $row instanceof stdClass && $row->approved_fingerprint !== null ? (string) $row->approved_fingerprint : null,
            'version' => $row instanceof stdClass && $row->approved_version !== null ? (int) $row->approved_version : null,
        ];
    }

    public function find(string $workspaceId, string $id, ?array $visibleProjectIds): ?BlueprintView
    {
        if (! Str::isUuid($id)) {
            return null;
        }

        $row = $this->base($workspaceId, $visibleProjectIds)->where('bp.id', $id)->first();

        return $row instanceof stdClass ? $this->hydrate([$row])[0] : null;
    }

    public function list(string $workspaceId, ?array $visibleProjectIds, ?string $status, ?string $projectId, int $page, int $perPage): array
    {
        $query = $this->base($workspaceId, $visibleProjectIds)
            ->when($status !== null && $status !== '', static fn (Builder $q) => $q->where('bp.status', $status))
            ->when($projectId !== null && Str::isUuid($projectId), static fn (Builder $q) => $q->where('bp.project_id', $projectId));
        $total = (clone $query)->count();
        $rows = array_values(array_filter($query->orderByDesc('bp.updated_at')->orderBy('bp.id')->forPage($page, $perPage)->get()->all(), static fn (mixed $r): bool => $r instanceof stdClass));

        return ['rows' => $this->hydrate($rows), 'total' => $total];
    }

    public function options(string $workspaceId, string $projectId): array
    {
        $clientId = $this->clientOf($workspaceId, $projectId);
        $rows = static fn (Builder $q): array => array_values(array_map(static fn (mixed $r): array => (array) $r, $q->get()->all()));

        return [
            'accounts' => $rows(DB::table('project_integration_bindings as b')->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
                ->where('b.workspace_id', $workspaceId)->where('b.project_id', $projectId)
                ->orderBy('a.provider')->orderBy('a.name')
                ->select(['a.id', 'a.provider', 'a.name', 'a.currency', 'a.timezone', 'a.status'])),
            'tracking_links' => $rows(DB::table('tracking_links')->where('workspace_id', $workspaceId)->where('project_id', $projectId)
                ->where('status', 'active')->orderBy('name')->select(['id', 'name', 'destination_url', 'platform', 'utm_campaign'])),
            'conversions' => $rows(DB::table('event_definitions')->where('workspace_id', $workspaceId)->where('client_id', $clientId)
                ->where('is_conversion', true)->orderBy('name')->select(['id', 'name', 'normalized_event', 'is_primary'])),
            'creatives' => $rows(DB::table('creative_requests')->where('workspace_id', $workspaceId)->where('client_id', $clientId)
                ->whereNotIn('state', ['cancelled'])->orderByDesc('updated_at')->limit(100)->select(['id', 'title', 'state', 'provider', 'placement'])),
        ];
    }

    /** @return array<string, mixed> */
    private function columns(BlueprintInput $input): array
    {
        return [
            'name' => $input->name,
            'objective' => $input->objective->value,
            'budget_type' => $input->budgetType,
            'budget_minor' => $input->budgetMinor,
            'currency' => strtoupper($input->currency),
            'starts_at' => $input->startsAt->format(DATE_ATOM),
            'ends_at' => $input->endsAt?->format(DATE_ATOM),
            'tracking_link_id' => $input->trackingLinkId,
            'landing_url' => $input->landingUrl,
            'conversion_event_id' => $input->conversionEventId,
            'targeting' => json_encode((object) $input->targeting, JSON_THROW_ON_ERROR),
            'placement' => json_encode((object) $input->placement, JSON_THROW_ON_ERROR),
            'ad_copy' => json_encode((object) $input->adCopy, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param  array<string, string>  $targets
     * @param  list<string>  $creatives
     */
    private function replaceChildren(string $id, array $targets, array $creatives): void
    {
        DB::table('campaign_blueprint_targets')->where('blueprint_id', $id)->delete();
        DB::table('campaign_blueprint_creatives')->where('blueprint_id', $id)->delete();

        foreach ($targets as $accountId => $provider) {
            DB::table('campaign_blueprint_targets')->insert(['id' => (string) Str::uuid7(), 'blueprint_id' => $id, 'external_account_id' => $accountId, 'provider' => $provider, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (array_values(array_unique($creatives)) as $creativeId) {
            DB::table('campaign_blueprint_creatives')->insert(['id' => (string) Str::uuid7(), 'blueprint_id' => $id, 'creative_request_id' => $creativeId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @param list<string>|null $visibleProjectIds */
    private function base(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        return DB::table('campaign_blueprints as bp')
            ->join('clients as c', 'c.id', '=', 'bp.client_id')
            ->join('projects as p', 'p.id', '=', 'bp.project_id')
            ->leftJoin('tracking_links as tl', 'tl.id', '=', 'bp.tracking_link_id')
            ->leftJoin('event_definitions as ev', 'ev.id', '=', 'bp.conversion_event_id')
            ->leftJoin('users as cu', 'cu.id', '=', 'bp.created_by')
            ->leftJoin('users as su', 'su.id', '=', 'bp.submitted_by')
            ->leftJoin('users as au', 'au.id', '=', 'bp.approved_by')
            ->where('bp.workspace_id', $workspaceId)
            ->when($visibleProjectIds !== null, static fn (Builder $q) => $q->whereIn('bp.project_id', $visibleProjectIds ?? []))
            ->select([
                'bp.*', 'c.name as client_name', 'p.name as project_name',
                'tl.name as link_name', 'tl.destination_url as link_destination', 'ev.name as conversion_name',
                'cu.name as created_by_name', 'su.name as submitted_by_name', 'au.name as approved_by_name',
            ]);
    }

    /**
     * @param  list<stdClass>  $rows
     * @return list<BlueprintView>
     */
    private function hydrate(array $rows): array
    {
        $ids = array_map(static fn (stdClass $r): string => (string) $r->id, $rows);
        $targets = [];
        $creatives = [];

        foreach (DB::table('campaign_blueprint_targets as t')->join('external_accounts as a', 'a.id', '=', 't.external_account_id')
            ->whereIn('t.blueprint_id', $ids)->orderBy('a.provider')->orderBy('a.name')
            ->get(['t.blueprint_id', 't.external_account_id', 't.provider', 'a.name', 'a.currency']) as $t) {
            $targets[(string) $t->blueprint_id][] = ['external_account_id' => (string) $t->external_account_id, 'provider' => (string) $t->provider, 'name' => (string) $t->name, 'currency' => $t->currency === null ? null : (string) $t->currency];
        }

        foreach (DB::table('campaign_blueprint_creatives as bc')->join('creative_requests as cr', 'cr.id', '=', 'bc.creative_request_id')
            ->whereIn('bc.blueprint_id', $ids)->orderBy('cr.title')
            ->get(['bc.blueprint_id', 'cr.id', 'cr.title', 'cr.state']) as $c) {
            $creatives[(string) $c->blueprint_id][] = ['id' => (string) $c->id, 'title' => (string) $c->title, 'state' => (string) $c->state];
        }

        $date = static fn (mixed $v): ?DateTimeImmutable => $v === null ? null : new DateTimeImmutable((string) $v);
        $json = static fn (mixed $v): array => is_string($v) ? (array) json_decode($v, true) : [];

        return array_map(static fn (stdClass $r): BlueprintView => new BlueprintView(
            id: (string) $r->id,
            clientId: (string) $r->client_id,
            clientName: (string) $r->client_name,
            projectId: (string) $r->project_id,
            projectName: (string) $r->project_name,
            name: (string) $r->name,
            objective: (string) $r->objective,
            budgetType: (string) $r->budget_type,
            budgetMinor: (int) $r->budget_minor,
            currency: (string) $r->currency,
            startsAt: new DateTimeImmutable((string) $r->starts_at),
            endsAt: $date($r->ends_at),
            targets: $targets[(string) $r->id] ?? [],
            trackingLink: $r->tracking_link_id === null ? null : ['id' => (string) $r->tracking_link_id, 'name' => (string) $r->link_name, 'destination_url' => (string) $r->link_destination],
            landingUrl: $r->landing_url === null ? null : (string) $r->landing_url,
            conversion: $r->conversion_event_id === null ? null : ['id' => (string) $r->conversion_event_id, 'name' => (string) $r->conversion_name],
            targeting: $json($r->targeting),
            placement: $json($r->placement),
            adCopy: $json($r->ad_copy),
            creatives: $creatives[(string) $r->id] ?? [],
            status: (string) $r->status,
            version: (int) $r->version,
            statusReason: $r->status_reason === null ? null : (string) $r->status_reason,
            createdBy: $r->created_by_name === null ? null : (string) $r->created_by_name,
            submittedBy: $r->submitted_by_name === null ? null : (string) $r->submitted_by_name,
            submittedAt: $date($r->submitted_at),
            approvedBy: $r->approved_by_name === null ? null : (string) $r->approved_by_name,
            approvedAt: $date($r->approved_at),
            updatedAt: new DateTimeImmutable((string) $r->updated_at),
        ), $rows);
    }
}
