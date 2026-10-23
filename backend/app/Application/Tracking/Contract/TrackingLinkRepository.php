<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Application\Tracking\DTO\LinkTarget;
use App\Application\Tracking\DTO\TrackingLinkView;
use App\Domain\Tracking\LinkStatus;

interface TrackingLinkRepository
{
    /**
     * The client a project belongs to, when the project is in this workspace and visible to the reader.
     *
     * @param  list<string>|null  $visibleProjectIds  null = every project
     */
    public function clientOfProject(string $workspaceId, ?array $visibleProjectIds, string $projectId): ?string;

    /**
     * @param  array<string, mixed>  $values  columns for tracking_links, already validated
     */
    public function create(string $workspaceId, string $clientId, string $token, string $createdBy, array $values): TrackingLinkView;

    /** @param array<string, mixed> $values */
    public function update(string $workspaceId, string $id, array $values): TrackingLinkView;

    public function setStatus(string $workspaceId, string $id, LinkStatus $status): void;

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?TrackingLinkView;

    /**
     * @param  list<string>|null  $visibleProjectIds
     * @return list<TrackingLinkView>
     */
    public function list(string $workspaceId, ?array $visibleProjectIds, ?string $projectId, ?string $clientId, ?LinkStatus $status): array;

    /** Used by the public redirect: by token, in no workspace in particular. */
    public function findTarget(string $token): ?LinkTarget;

    public function tokenExists(string $token): bool;

    /** @return list<array{id: string, name: string, patterns: array<string, string>}> */
    public function templates(string $workspaceId): array;

    /** @param array<string, string> $patterns */
    public function saveTemplate(string $workspaceId, string $id, string $createdBy, string $name, array $patterns): void;
}
