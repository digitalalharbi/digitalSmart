<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Influencers\Contract\InfluencerRepository;
use App\Application\Influencers\DTO\InfluencerInput;
use App\Application\Influencers\DTO\InfluencerView;
use App\Application\Shared\Contract\IdentifierGenerator;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class EloquentInfluencerRepository implements InfluencerRepository
{
    public function __construct(private IdentifierGenerator $identifiers) {}

    public function list(string $workspaceId, ?array $visibleProjectIds, ?string $clientId, bool $includeArchived): array
    {
        $query = $this->base()->where('i.workspace_id', $workspaceId);
        $this->visible($query, $workspaceId, $visibleProjectIds);

        if ($clientId !== null) {
            $query->where('i.client_id', $clientId);
        }

        if (! $includeArchived) {
            $query->where('i.status', 'active');
        }

        $views = [];

        foreach ($query->orderBy('i.name')->orderBy('i.id')->limit(500)->get() as $row) {
            if ($row instanceof stdClass) {
                $views[] = $this->view($row);
            }
        }

        return $views;
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?InfluencerView
    {
        $query = $this->base()->where('i.workspace_id', $workspaceId)->where('i.id', $id);
        $this->visible($query, $workspaceId, $visibleProjectIds);
        $row = $query->first();

        return $row instanceof stdClass ? $this->view($row) : null;
    }

    /** @param list<string>|null $visibleProjectIds */
    public function clientVisible(string $workspaceId, ?array $visibleProjectIds, string $clientId): bool
    {
        $query = DB::table('clients as c')->where('c.workspace_id', $workspaceId)->where('c.id', $clientId);

        if ($visibleProjectIds !== null) {
            $query->whereExists(static function (Builder $sub) use ($visibleProjectIds): void {
                $sub->selectRaw('1')->from('projects as p')->whereColumn('p.client_id', 'c.id')->whereIn('p.id', $visibleProjectIds);
            });
        }

        return $query->exists();
    }

    public function nameTaken(string $workspaceId, string $clientId, string $name, ?string $exceptId): bool
    {
        $query = DB::table('influencers')
            ->where('workspace_id', $workspaceId)->where('client_id', $clientId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)]);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    public function create(string $workspaceId, string $createdBy, InfluencerInput $input): InfluencerView
    {
        $id = $this->identifiers->generate();
        $now = now();

        DB::table('influencers')->insert([
            'id' => $id, 'workspace_id' => $workspaceId, 'client_id' => $input->clientId, 'name' => $input->name,
            'handle' => $input->handle, 'platform' => $input->platform, 'notes' => $input->notes, 'status' => $input->status,
            'created_by' => $createdBy, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $this->reload($workspaceId, $id);
    }

    public function update(string $workspaceId, string $id, InfluencerInput $input): InfluencerView
    {
        DB::table('influencers')->where('workspace_id', $workspaceId)->where('id', $id)->update([
            'name' => $input->name, 'handle' => $input->handle, 'platform' => $input->platform,
            'notes' => $input->notes, 'status' => $input->status, 'updated_at' => now(),
        ]);

        return $this->reload($workspaceId, $id);
    }

    public function belongsToClient(string $workspaceId, string $influencerId, string $clientId): bool
    {
        return DB::table('influencers')
            ->where('workspace_id', $workspaceId)->where('id', $influencerId)->where('client_id', $clientId)
            ->exists();
    }

    public function linkIds(string $workspaceId, array $influencerIds): array
    {
        if ($influencerIds === []) {
            return [];
        }

        $byInfluencer = [];

        foreach (DB::table('tracking_links')->where('workspace_id', $workspaceId)->whereIn('influencer_id', $influencerIds)->get(['id', 'influencer_id']) as $row) {
            $byInfluencer[(string) $row->influencer_id][] = (string) $row->id;
        }

        return $byInfluencer;
    }

    private function reload(string $workspaceId, string $id): InfluencerView
    {
        $row = $this->base()->where('i.workspace_id', $workspaceId)->where('i.id', $id)->first();

        return $this->view($row instanceof stdClass ? $row : new stdClass);
    }

    private function base(): Builder
    {
        return DB::table('influencers as i')
            ->join('clients as c', 'c.id', '=', 'i.client_id')
            ->select('i.*', 'c.name as client_name');
    }

    /** @param list<string>|null $visibleProjectIds */
    private function visible(Builder $query, string $workspaceId, ?array $visibleProjectIds): void
    {
        if ($visibleProjectIds === null) {
            return;
        }

        $query->whereIn('i.client_id', static function (Builder $sub) use ($workspaceId, $visibleProjectIds): void {
            $sub->select('client_id')->from('projects')->where('workspace_id', $workspaceId)->whereIn('id', $visibleProjectIds);
        });
    }

    private function view(stdClass $row): InfluencerView
    {
        return new InfluencerView(
            (string) $row->id, (string) $row->client_id, (string) $row->client_name, (string) $row->name,
            is_string($row->handle ?? null) ? $row->handle : null,
            is_string($row->platform ?? null) ? $row->platform : null,
            is_string($row->notes ?? null) ? $row->notes : null,
            (string) $row->status,
            new DateTimeImmutable((string) $row->created_at),
        );
    }
}
