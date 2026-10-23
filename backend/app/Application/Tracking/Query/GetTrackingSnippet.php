<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Contract\TrackingSettingsRepository;
use App\Domain\Access\Permission;
use App\Domain\Tracking\DataLayerMap;

/** The data layer names a project uses and the snippet a landing page carries to report into this product. */
final readonly class GetTrackingSnippet
{
    public function __construct(
        private TrackingSettingsRepository $settings,
        private TrackingLinkRepository $links,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return array{data_layer: array<string, string>, defaults: array<string, string>, snippet: string} */
    public function __invoke(string $projectId, string $apiBase): array
    {
        $this->guard->ensure(Permission::TrackingView);

        if ($this->links->clientOfProject($this->actor->workspaceId()->value, $this->visibility->visibleProjectIds(), $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        $map = DataLayerMap::resolve($this->settings->dataLayerOverrides($projectId));

        return ['data_layer' => $map, 'defaults' => DataLayerMap::defaults(), 'snippet' => DataLayerMap::snippet($apiBase, $map)];
    }
}
