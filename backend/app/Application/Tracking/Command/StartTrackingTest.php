<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Application\Tracking\Contract\OpaqueTokens;
use App\Application\Tracking\Contract\TrackingEventStore;
use App\Application\Tracking\Contract\TrackingLinkRepository;
use App\Application\Tracking\DTO\LinkTarget;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Domain\Access\Permission;
use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\DestinationUrl;
use App\Domain\Tracking\Exception\UnsafeDestination;
use App\Domain\Tracking\TrafficClass;

/**
 * Begin a test visit to a link.
 *
 * It behaves like a click in every way except one: the session is marked `test`, so everything that follows from it —
 * page reports, a form submission — is kept, shown in the debug console, and left out of every count and funnel. A test
 * submission creates no lead and nothing is ever dispatched to a provider from it.
 */
final readonly class StartTrackingTest
{
    /** @param list<string> $ownHosts */
    public function __construct(
        private TrackingLinkRepository $links,
        private TrackingEventStore $events,
        private OpaqueTokens $tokens,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
        private array $ownHosts = [],
    ) {}

    /** @return array{touchpoint: string, url: string} */
    public function __invoke(string $linkId): array
    {
        $this->guard->ensure(Permission::TrackingManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $link = $this->links->find($workspaceId, $this->visibility->visibleProjectIds(), $linkId) ?? throw ResourceNotFound::of('Tracking link');

        try {
            $destination = DestinationUrl::parse($link->destinationUrl, $this->ownHosts);
        } catch (UnsafeDestination $e) {
            throw new TrackingRefused($e->getMessage(), 0, $e);
        }

        $touchpoint = $this->tokens->make(20);
        $target = new LinkTarget($link->id, $workspaceId, $link->projectId, $link->platform, $link->channel, $link->destinationUrl, $link->utm, $link->customParams, $link->status, $link->expiresAt);

        $this->events->recordClick(
            $target, $this->tokens->make(20), $this->tokens->make(20), $touchpoint,
            $this->tokens->make(24), $this->tokens->make(24),
            TrafficClass::Test, ConsentState::Unknown, null, 'desktop', 'other', (string) parse_url($destination->value, PHP_URL_PATH), [], $this->clock->now(),
        );

        return ['touchpoint' => $touchpoint, 'url' => $destination->with([...$link->utm, ...$link->customParams, 'ds_tp' => $touchpoint])];
    }
}
