<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Command\SaveConversionMapping;
use App\Application\Tracking\Contract\ConversionMappingRepository;
use App\Application\Tracking\Contract\ProviderDispatchStore;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\Contract\TrackingPolicyRepository;
use App\Domain\Access\Permission;
use App\Domain\Tracking\Dispatch\ProviderConversionCatalogue;

/** A project's conversion mappings, what each provider documents, the consent and retention policy, and the dispatch log. */
final readonly class GetConversions
{
    public function __construct(
        private ConversionMappingRepository $mappings,
        private ProviderDispatchStore $dispatches,
        private TrackingPolicyRepository $policies,
        private TrackingLinkRepository $links,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(string $projectId): array
    {
        $this->guard->ensure(Permission::TrackingView);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($this->links->clientOfProject($workspaceId, $this->visibility->visibleProjectIds(), $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        $policy = $this->policies->for($projectId);
        $providers = [];

        foreach (ProviderConversionCatalogue::listed() as $provider) {
            $entry = ProviderConversionCatalogue::for($provider);
            $providers[] = [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'support' => $entry['support']->value,
                'mechanism' => $entry['mechanism'],
                'events' => $entry['events'],
                'event_rule' => $entry['event_rule'],
                'dedup' => $entry['dedup'],
                'needs_click_id' => $entry['needs_click_id'],
                'source' => $entry['source'],
                'caveat' => $entry['caveat'],
                'read_on' => $entry['source'] === null ? null : ProviderConversionCatalogue::READ_ON,
            ];
        }

        return [
            'events' => SaveConversionMapping::EVENTS,
            'providers' => $providers,
            'mappings' => $this->mappings->list($workspaceId, $projectId),
            'policy' => ['consent_policy' => $policy['consent_policy']->value, 'retention_days' => $policy['retention_days']],
            'dispatches' => $this->dispatches->list($workspaceId, $projectId, 50),
        ];
    }
}
