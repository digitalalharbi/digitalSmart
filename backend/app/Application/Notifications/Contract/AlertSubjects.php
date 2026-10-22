<?php

declare(strict_types=1);

namespace App\Application\Notifications\Contract;

use DateTimeImmutable;

/**
 * The few facts an alert needs about the thing it is about: its name, and the project and client the resolver
 * narrows the audience by.
 */
interface AlertSubjects
{
    /** An active owner of the workspace, whose scope the signals are read in. Null for a workspace with none. */
    public function ownerOf(string $workspaceId): ?string;

    /**
     * @param  list<string>  $campaignIds
     * @return array<string, array{label: string, project_id: ?string, client_id: ?string}>
     */
    public function campaigns(string $workspaceId, array $campaignIds): array;

    /**
     * Campaigns whose end date passed in the window.
     *
     * @return list<array{id: string, label: string, project_id: ?string, client_id: ?string, ended_at: string}>
     */
    public function endedBetween(string $workspaceId, DateTimeImmutable $since, DateTimeImmutable $until): array;

    /**
     * @param  list<string>  $creativeIds
     * @return array<string, array{label: string, project_id: ?string, client_id: ?string}>
     */
    public function creatives(string $workspaceId, array $creativeIds): array;
}
