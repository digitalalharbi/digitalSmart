<?php

declare(strict_types=1);

namespace App\Application\Influencers\Contract;

use App\Application\Influencers\DTO\InfluencerInput;
use App\Application\Influencers\DTO\InfluencerView;

interface InfluencerRepository
{
    /**
     * The influencers of clients this reader may see: all of the workspace's when `$visibleProjectIds` is null, otherwise only
     * those of a client who has one of the visible projects.
     *
     * @param  list<string>|null  $visibleProjectIds
     * @return list<InfluencerView>
     */
    public function list(string $workspaceId, ?array $visibleProjectIds, ?string $clientId, bool $includeArchived): array;

    /** @param list<string>|null $visibleProjectIds */
    public function find(string $workspaceId, ?array $visibleProjectIds, string $id): ?InfluencerView;

    /**
     * Whether this client is the workspace's own and the reader may see it.
     *
     * @param  list<string>|null  $visibleProjectIds
     */
    public function clientVisible(string $workspaceId, ?array $visibleProjectIds, string $clientId): bool;

    public function nameTaken(string $workspaceId, string $clientId, string $name, ?string $exceptId): bool;

    public function create(string $workspaceId, string $createdBy, InfluencerInput $input): InfluencerView;

    public function update(string $workspaceId, string $id, InfluencerInput $input): InfluencerView;

    /** Whether this influencer is the workspace's own, and the named client's. */
    public function belongsToClient(string $workspaceId, string $influencerId, string $clientId): bool;

    /**
     * Link ids per influencer, for the totals.
     *
     * @param  list<string>  $influencerIds
     * @return array<string, list<string>>
     */
    public function linkIds(string $workspaceId, array $influencerIds): array;
}
