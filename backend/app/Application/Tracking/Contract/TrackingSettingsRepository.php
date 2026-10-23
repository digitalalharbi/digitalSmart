<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

interface TrackingSettingsRepository
{
    /**
     * The overrides a project saved: browser event => data layer name. Empty when it has none.
     *
     * @return array<string, string>
     */
    public function dataLayerOverrides(string $projectId): array;

    /** @param array<string, string> $overrides */
    public function saveDataLayer(string $workspaceId, string $projectId, array $overrides, string $actorId): void;
}
