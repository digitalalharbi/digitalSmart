<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\DTO\LinkTarget;
use App\Application\Tracking\DTO\TrackingLinkView;
use App\Domain\Tracking\LinkStatus;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use stdClass;

final readonly class EloquentTrackingLinkRepository implements TrackingLinkRepository
{
    public function clientOfProject(string $workspaceId, ?array $visibleProjectIds, string $projectId): ?string
    {
        if ($visibleProjectIds !== null && ! in_array($projectId, $visibleProjectIds, true)) {
            return null;
        }

        $client = DB::table('projects')
            ->where('workspace_id', $workspaceId)
            ->where('id', $projectId)
            ->whereNull('deleted_at')
            ->value('client_id');

        return is_string($client) ? $client : null;
    }

    public function create(string $workspaceId, string $clientId, string $token, string $createdBy, array $values): TrackingLinkView
    {
        $id = (string) Str::uuid7();
        $now = now();

        DB::table('tracking_links')->insert([
            ...$this->columns($values),
            'id' => $id,
            'workspace_id' => $workspaceId,
            'client_id' => $clientId,
            'public_token' => $token,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($workspaceId, null, $id) ?? throw new LogicException('A link just written could not be read.');
    }

    public function update(string $workspaceId, string $id, array $values): TrackingLinkView
    {
        DB::table('tracking_links')->where('workspace_id', $workspaceId)->where('id', $id)
            ->update([...$this->columns($values), 'updated_at' => now()]);

        return $this->find($workspaceId, null, $id) ?? throw new LogicException('A link just written could not be read.');
    }

    public function setStatus(string $workspaceId, string $id, LinkStatus $status): void
    {
        DB::table('tracking_links')->where('workspace_id', $workspaceId)->where('id', $id)
            ->update(['status' => $status->value, 'updated_at' => now()]);
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?TrackingLinkView
    {
        $query = $this->base()->where('l.workspace_id', $workspaceId)->where('l.id', $id);

        if ($visibleProjectIds !== null) {
            $query->whereIn('l.project_id', $visibleProjectIds);
        }

        $row = $query->first();

        return $row instanceof stdClass ? $this->view($row) : null;
    }

    public function list(string $workspaceId, ?array $visibleProjectIds, ?string $projectId, ?string $clientId, ?LinkStatus $status): array
    {
        $query = $this->base()->where('l.workspace_id', $workspaceId);

        if ($visibleProjectIds !== null) {
            $query->whereIn('l.project_id', $visibleProjectIds);
        }

        if ($projectId !== null) {
            $query->where('l.project_id', $projectId);
        }

        if ($clientId !== null) {
            $query->where('l.client_id', $clientId);
        }

        if ($status instanceof LinkStatus) {
            $query->where('l.status', $status->value);
        } else {
            $query->where('l.status', '!=', LinkStatus::Archived->value);
        }

        $rows = [];

        foreach ($query->orderByDesc('l.created_at')->orderByDesc('l.id')->limit(500)->get() as $row) {
            if ($row instanceof stdClass) {
                $rows[] = $this->view($row);
            }
        }

        return $rows;
    }

    public function findTarget(string $token): ?LinkTarget
    {
        $row = DB::table('tracking_links')->where('public_token', $token)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        $utm = array_filter([
            'utm_source' => $row->utm_source,
            'utm_medium' => $row->utm_medium,
            'utm_campaign' => $row->utm_campaign,
            'utm_content' => $row->utm_content,
            'utm_term' => $row->utm_term,
        ], static fn (mixed $v): bool => is_string($v) && $v !== '');

        return new LinkTarget(
            (string) $row->id, (string) $row->workspace_id, (string) $row->project_id,
            is_string($row->platform) ? $row->platform : null,
            (string) $row->channel,
            (string) $row->destination_url,
            $utm,
            $this->strings($row->custom_params),
            LinkStatus::from((string) $row->status),
            is_string($row->expires_at) ? new DateTimeImmutable($row->expires_at) : null,
        );
    }

    public function tokenExists(string $token): bool
    {
        return DB::table('tracking_links')->where('public_token', $token)->exists();
    }

    public function templates(string $workspaceId): array
    {
        $out = [];

        foreach (DB::table('tracking_link_templates')->where('workspace_id', $workspaceId)->orderBy('name')->get() as $row) {
            $out[] = ['id' => (string) $row->id, 'name' => (string) $row->name, 'patterns' => $this->strings($row->patterns)];
        }

        return $out;
    }

    public function saveTemplate(string $workspaceId, string $id, string $createdBy, string $name, array $patterns): void
    {
        $existing = DB::table('tracking_link_templates')->where('workspace_id', $workspaceId)->where('name', $name)->value('id');
        $now = now();

        if (is_string($existing)) {
            DB::table('tracking_link_templates')->where('id', $existing)
                ->update(['patterns' => json_encode($patterns, JSON_THROW_ON_ERROR), 'updated_at' => $now]);

            return;
        }

        DB::table('tracking_link_templates')->insert([
            'id' => $id, 'workspace_id' => $workspaceId, 'name' => $name, 'created_by' => $createdBy,
            'patterns' => json_encode($patterns, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function base(): Builder
    {
        return DB::table('tracking_links as l')
            ->join('clients as c', 'c.id', '=', 'l.client_id')
            ->join('projects as p', 'p.id', '=', 'l.project_id')
            ->select('l.*', 'c.name as client_name', 'p.name as project_name');
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function columns(array $values): array
    {
        $values['custom_params'] = json_encode($values['custom_params'] ?? [], JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);

        if (($values['expires_at'] ?? null) instanceof DateTimeImmutable) {
            $values['expires_at'] = $values['expires_at']->format('Y-m-d H:i:sP');
        }

        unset($values['client_id']);

        return $values;
    }

    private function view(stdClass $row): TrackingLinkView
    {
        $utm = array_filter([
            'utm_source' => $row->utm_source,
            'utm_medium' => $row->utm_medium,
            'utm_campaign' => $row->utm_campaign,
            'utm_content' => $row->utm_content,
            'utm_term' => $row->utm_term,
        ], static fn (mixed $v): bool => is_string($v) && $v !== '');

        return new TrackingLinkView(
            (string) $row->id, (string) $row->client_id, (string) $row->client_name,
            (string) $row->project_id, (string) $row->project_name, (string) $row->public_token,
            (string) $row->name,
            is_string($row->platform) ? $row->platform : null,
            is_string($row->campaign) ? $row->campaign : null,
            is_string($row->ad_group) ? $row->ad_group : null,
            is_string($row->ad) ? $row->ad : null,
            is_string($row->creative) ? $row->creative : null,
            (string) $row->channel,
            (string) $row->destination_url,
            $utm,
            $this->strings($row->custom_params),
            is_string($row->objective) ? $row->objective : null,
            LinkStatus::from((string) $row->status),
            is_string($row->expires_at) ? new DateTimeImmutable($row->expires_at) : null,
            is_string($row->notes) ? $row->notes : null,
            new DateTimeImmutable((string) $row->created_at),
            null,
            $this->id($row->campaign_id ?? null), $this->id($row->ad_group_id ?? null), $this->id($row->ad_id ?? null),
            $this->id($row->creative_id ?? null), $this->id($row->external_account_id ?? null),
            is_string($row->conversion_goal ?? null) ? $row->conversion_goal : null,
        );
    }

    private function id(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, string> */
    private function strings(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : [];
        $out = [];

        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_scalar($value)) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }
}
