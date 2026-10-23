<?php

declare(strict_types=1);

namespace App\Application\Tracking\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\TrackingHealthReadModel;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Domain\Access\Permission;
use App\Domain\Integration\ConnectionStatus;
use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderApiStatus;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Tracking\Health\TrackingHealthComposer;

/** Is the tracking for one project (or one link) working, piece by piece, with the evidence for each verdict. */
final readonly class GetTrackingHealthReport
{
    /** Providers the health checks look at. */
    private const array PROVIDERS = [
        'meta' => Provider::Meta, 'tiktok' => Provider::TikTok, 'snapchat' => Provider::Snapchat, 'linkedin' => Provider::LinkedIn,
        'google_ads' => Provider::GoogleAds, 'x' => Provider::X, 'openai' => Provider::OpenAI,
        'google_analytics' => Provider::GoogleAnalytics, 'google_tag_manager' => Provider::GoogleTagManager,
    ];

    public function __construct(
        private TrackingHealthReadModel $read,
        private TrackingLinkRepository $links,
        private ProviderConnectionRepository $connections,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    /** @return array{checks: list<array<string, mixed>>, summary: array{needs_attention: int, by_state: array<string, int>}, scope: string} */
    public function __invoke(string $projectId, ?string $linkId): array
    {
        $this->guard->ensure(Permission::TrackingView);

        $workspaceId = $this->actor->workspaceId();
        $visible = $this->visibility->visibleProjectIds();

        if ($this->links->clientOfProject($workspaceId->value, $visible, $projectId) === null) {
            throw ResourceNotFound::of('Project');
        }

        if ($linkId !== null) {
            $link = $this->links->find($workspaceId->value, $visible, $linkId);

            if ($link === null || $link->projectId !== $projectId) {
                throw ResourceNotFound::of('Tracking link');
            }
        }

        $now = $this->clock->now();
        $facts = $this->read->facts($workspaceId->value, $projectId, $linkId, $now);

        $connections = [];

        foreach (self::PROVIDERS as $slug => $provider) {
            $connection = $this->connections->agencyConnection($workspaceId, $provider);
            $connections[$slug] = [
                'connected' => $connection !== null && $connection->status() === ConnectionStatus::Connected,
                'sandbox' => $connection !== null && $connection->credentials()->isSandbox($provider->requiredCredentialKeys()),
                'unsupported' => $provider->apiStatus() === ProviderApiStatus::Unavailable,
            ];
        }

        /** @var array{links_active: int, events_24h: int, last_event_at: ?int, clicks_7d: int, landing_views_7d: int, clicks_with_click_id_7d: int, leads_7d: int, leads_with_evidence_7d: int, consent: array{unknown: int, granted: int, denied: int, not_required: int}, ga4: ?array{last_synced_at: ?int, last_event_at: ?int}, gtm_containers: int} $facts */
        $checks = TrackingHealthComposer::compose($facts, $connections, $now->getTimestamp());

        return [
            'checks' => array_map(static fn ($c): array => $c->toArray(), $checks),
            'summary' => TrackingHealthComposer::summary($checks),
            'scope' => $linkId === null ? 'project' : 'link',
        ];
    }
}
