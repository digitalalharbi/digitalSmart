<?php

declare(strict_types=1);

namespace App\Application\Access\Command;

use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\AccessSettings;
use App\Application\Access\Exception\AccessRequestRefused;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\OwnerDirectory;
use App\Domain\Access\Permission;
use App\Domain\Leads\Automation\LeadSla;

/** The workspace's promise about how soon a request is tried, and who gets the ones nobody was given. */
final readonly class SaveAccessSettings
{
    public function __construct(
        private AccessRequestRepository $requests,
        private OwnerDirectory $owners,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private Clock $clock,
    ) {}

    public function __invoke(int $responseMinutes, ?string $defaultOwnerId): AccessSettings
    {
        $this->guard->ensure(Permission::UsersInvite);

        $workspace = $this->actor->workspaceId()->value;
        $minutes = max(LeadSla::MIN_MINUTES, min(LeadSla::MAX_MINUTES, $responseMinutes));

        if ($defaultOwnerId !== null) {
            $eligible = array_map(static fn ($c): string => $c->id, $this->owners->peopleWhoCanSee($workspace, null, Permission::UsersInvite));

            if (! in_array($defaultOwnerId, $eligible, true)) {
                throw AccessRequestRefused::ownerCannotAnswer();
            }
        }

        $this->requests->saveSettings($workspace, $minutes, $defaultOwnerId, $this->clock->now());

        return $this->requests->settings($workspace);
    }
}
