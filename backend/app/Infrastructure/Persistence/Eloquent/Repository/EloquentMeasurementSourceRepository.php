<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Measurement\Contract\MeasurementSourceRepository;
use App\Application\Measurement\DTO\MeasurementSourceInput;
use App\Application\Measurement\DTO\MeasurementSourceView;
use App\Application\Shared\Contract\Clock;
use App\Domain\Integration\Provider;
use App\Domain\Measurement\Exception\InvalidMeasurementSource;
use App\Domain\Measurement\MeasurementFreshness;
use App\Domain\Measurement\MeasurementSourceKind;
use App\Infrastructure\Persistence\Eloquent\Model\MeasurementSource;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;

final readonly class EloquentMeasurementSourceRepository implements MeasurementSourceRepository
{
    /** @var list<string> */
    private const RELATIONS = ['client:id,name', 'project:id,name'];

    public function __construct(private Clock $clock) {}

    /** @return list<MeasurementSourceView> */
    public function visibleTo(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId = null,
        ?string $projectId = null,
    ): array {
        $query = $this->visibleQuery($workspaceId, $visibleProjectIds)->with(self::RELATIONS);

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        if ($projectId !== null) {
            /*
             * A project's sources *and* the client-level ones it inherits. Measurement is
             * agreed per client before a project uses it, so filtering strictly by project
             * would hide the GA4 property that is actually measuring the work.
             */
            $query->where(function (Builder $scoped) use ($projectId): void {
                $scoped->where('project_id', $projectId)->orWhereNull('project_id');
            });
        }

        $sources = $query->orderBy('kind')->orderBy('name')->get();

        return array_values(
            $sources->map(fn (MeasurementSource $s): MeasurementSourceView => $this->toView($s))->all(),
        );
    }

    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?MeasurementSourceView
    {
        $source = $this->visibleQuery($workspaceId, $visibleProjectIds)
            ->with(self::RELATIONS)
            ->whereKey($id)
            ->first();

        return $source instanceof MeasurementSource ? $this->toView($source) : null;
    }

    public function create(string $workspaceId, MeasurementSourceInput $input): MeasurementSourceView
    {
        try {
            $source = MeasurementSource::query()->create($this->attributes($workspaceId, $input));
        } catch (QueryException $exception) {
            /*
             * The unique index is the authority: two records for one property would double
             * every count read through it. Caught rather than pre-checked because a
             * check-then-insert can still lose a race, and the index cannot.
             */
            if ($this->isUniqueViolation($exception)) {
                throw InvalidMeasurementSource::alreadyRegistered($input->externalId);
            }

            throw $exception;
        }

        return $this->toView($this->reload($source));
    }

    public function update(string $workspaceId, string $id, MeasurementSourceInput $input): MeasurementSourceView
    {
        /** @var MeasurementSource $source */
        $source = MeasurementSource::query()->where('workspace_id', $workspaceId)->whereKey($id)->firstOrFail();

        try {
            $source->update($this->attributes($workspaceId, $input));
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                throw InvalidMeasurementSource::alreadyRegistered($input->externalId);
            }

            throw $exception;
        }

        return $this->toView($this->reload($source));
    }

    public function delete(string $workspaceId, string $id): void
    {
        // The observed counts cascade: they only ever meant anything read through this source.
        MeasurementSource::query()->where('workspace_id', $workspaceId)->whereKey($id)->delete();
    }

    /** @return array<string, mixed> */
    private function attributes(string $workspaceId, MeasurementSourceInput $input): array
    {
        return [
            'workspace_id' => $workspaceId,
            'client_id' => $input->clientId,
            'project_id' => $input->projectId,
            'provider' => $input->provider?->value,
            'kind' => $input->kind,
            'external_id' => $input->externalId,
            'name' => $input->name,
            'provider_connection_id' => $input->providerConnectionId,
            'is_active' => $input->isActive,
            'metadata' => $input->metadata,
        ];
    }

    /**
     * Sources this actor may see, applied in SQL.
     *
     * The same model as every other read here: a source outside the actor's projects is never
     * in the result set, and a client-level source is visible to anybody on one of that
     * client's projects.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return Builder<MeasurementSource>
     */
    private function visibleQuery(string $workspaceId, ?array $visibleProjectIds): Builder
    {
        $query = MeasurementSource::query()->where('workspace_id', $workspaceId);

        if ($visibleProjectIds === null) {
            return $query;
        }

        if ($visibleProjectIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scoped) use ($visibleProjectIds, $workspaceId): void {
            $scoped
                ->whereIn('project_id', $visibleProjectIds)
                ->orWhere(function (Builder $clientLevel) use ($visibleProjectIds, $workspaceId): void {
                    $clientLevel
                        ->whereNull('project_id')
                        ->whereIn('client_id', function (QueryBuilder $sub) use ($visibleProjectIds, $workspaceId): void {
                            $sub->select('client_id')
                                ->from('projects')
                                ->where('workspace_id', $workspaceId)
                                ->whereIn('id', $visibleProjectIds);
                        });
                });
        });
    }

    private function reload(MeasurementSource $source): MeasurementSource
    {
        /** @var MeasurementSource */
        return MeasurementSource::query()->with(self::RELATIONS)->whereKey($source->getKey())->firstOrFail();
    }

    private function toView(MeasurementSource $source): MeasurementSourceView
    {
        /** @var MeasurementSourceKind $kind */
        $kind = $source->getAttribute('kind');
        $lastSynced = $this->dateOrNull($source->getAttribute('last_synced_at'));
        $lastEvent = $this->dateOrNull($source->getAttribute('last_event_at'));

        /** @var array<string, mixed> $metadata */
        $metadata = $source->getAttribute('metadata') ?? [];

        return new MeasurementSourceView(
            id: (string) $source->getKey(),
            clientId: (string) $source->getAttribute('client_id'),
            clientName: (string) ($source->client?->getAttribute('name') ?? 'Unknown client'),
            projectId: $source->getAttribute('project_id') === null ? null : (string) $source->getAttribute('project_id'),
            projectName: $source->project?->getAttribute('name'),
            provider: $source->getAttribute('provider') instanceof Provider ? $source->getAttribute('provider') : null,
            kind: $kind,
            externalId: (string) $source->getAttribute('external_id'),
            name: (string) $source->getAttribute('name'),
            /*
             * Worked out here rather than stored, so it cannot be stale: freshness is a
             * statement about now, and a column would describe the moment it was written.
             */
            freshness: MeasurementFreshness::of($lastSynced, $lastEvent, $this->clock->now()),
            isActive: (bool) $source->getAttribute('is_active'),
            lastSyncedAt: $lastSynced,
            lastEventAt: $lastEvent,
            metadata: $metadata,
        );
    }

    private function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        return $value instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($value) : null;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return str_contains($exception->getMessage(), 'measurement_sources_unique');
    }
}
