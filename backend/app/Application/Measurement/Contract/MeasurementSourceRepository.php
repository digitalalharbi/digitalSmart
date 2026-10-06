<?php

declare(strict_types=1);

namespace App\Application\Measurement\Contract;

use App\Application\Measurement\DTO\MeasurementSourceInput;
use App\Application\Measurement\DTO\MeasurementSourceView;

interface MeasurementSourceRepository
{
    /**
     * Sources this actor may see.
     *
     * `$visibleProjectIds` of null means unrestricted. Otherwise a source is visible when it
     * belongs to one of those projects, or to a client that has one of them — because
     * measurement is agreed per client before a project uses it.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<MeasurementSourceView>
     */
    public function visibleTo(
        string $workspaceId,
        ?array $visibleProjectIds,
        ?string $clientId = null,
        ?string $projectId = null,
    ): array;

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?MeasurementSourceView;

    public function create(string $workspaceId, MeasurementSourceInput $input): MeasurementSourceView;

    public function update(string $workspaceId, string $id, MeasurementSourceInput $input): MeasurementSourceView;

    public function delete(string $workspaceId, string $id): void;
}
