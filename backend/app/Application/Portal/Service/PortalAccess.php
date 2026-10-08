<?php

declare(strict_types=1);

namespace App\Application\Portal\Service;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Portal\Contract\PortalRepository;
use App\Application\Portal\DTO\PortalEntry;
use App\Application\Portal\DTO\PortalReader;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Portal\PortalModule;
use App\Domain\Portal\PortalState;

/**
 * The one place that decides whether somebody may read a client's portal, and what of it.
 *
 * Everything the portal exposes goes through here, which is the point: a disclosure rule implemented twice is a
 * disclosure rule that disagrees with itself. Three decisions live in this class and nowhere else.
 *
 * **Who is reading.** `portal.view` marks agency staff, who read every page as a preview. `portal.read` without
 * it marks the client's own people. A reader holding neither has nothing here, which is why a creative assigned to
 * a client's project — plainly not their employee — reads nothing. The distinction is a permission rather than an
 * inference from project assignment, because assignment says what somebody works on and not who they work for.
 *
 * **Which clients they reach.** The clients that own the projects they are assigned to, which for a client's own
 * people is their identity in this product: there is no "client id" column on a user to be set wrongly. An agency
 * previewer with workspace-wide visibility reaches every client.
 *
 * **Whether a module is readable.** The portal must be active *and* the module exposed. Two conditions rather than
 * one, because a suspended portal with grants intact is the normal state of a paused engagement.
 *
 * **What a refusal looks like.** A module that is not exposed answers **404, not 403**: 403 confirms the surface
 * exists and is merely closed to this reader, and for a client that is a sentence about the agency's intentions.
 * The portal index is where a reader learns what they may open; a direct request for anything else is not a
 * question this product answers.
 */
final readonly class PortalAccess
{
    public function __construct(
        private PortalRepository $portals,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * Every portal this reader may open.
     *
     * Clients whose portal is not active are **included**, carrying their state. A reader whose portal was switched
     * off is owed the sentence that says so; dropping them from the list would render as "nothing has happened
     * yet", which is a different and wrong message.
     *
     * @return list<PortalEntry>
     */
    public function readable(): array
    {
        if (! $this->actor->isBound()) {
            return [];
        }

        /*
         * Preview wins, and the order is load-bearing. The owner role holds **every** permission by construction
         * — `SystemRole::Owner->permissions()` returns `Permission::cases()` — so `portal.read` cannot by itself
         * mean "is a client". What distinguishes the two is holding the agency's own `portal.view`: a reader with
         * it is staff previewing, and a reader with `portal.read` and without it is the client.
         */
        $isPreview = $this->guard->allows(Permission::PortalView);
        $isClient = ! $isPreview && $this->guard->allows(Permission::PortalRead);

        if (! $isClient && ! $isPreview) {
            /*
             * Neither the client's own people nor agency staff previewing. An empty list rather than a refusal:
             * there is nothing here for them, which is a different fact from being denied something.
             */
            return [];
        }

        $workspaceId = $this->actor->workspaceId()->value;
        $clients = $this->clients($workspaceId, $isPreview);

        if ($clients === []) {
            return [];
        }

        $states = $this->portals->stateFor($workspaceId, array_keys($clients));
        $exposed = $this->portals->exposedFor($workspaceId, array_keys($clients));

        $entries = [];

        foreach ($clients as $clientId => $name) {
            $state = $states[$clientId] ?? PortalState::NotConfigured;

            $entries[] = new PortalEntry(
                clientId: $clientId,
                clientName: $name,
                state: $state,
                modules: $state->allowsAccess() ? ($exposed[$clientId] ?? []) : [],
                isPreview: $isPreview,
            );
        }

        return $entries;
    }

    /**
     * Confirm this reader may read this module of this client's portal.
     *
     * @throws ResourceNotFound
     */
    public function ensure(string $clientId, PortalModule $module): PortalReader
    {
        foreach ($this->readable() as $entry) {
            if ($entry->clientId !== $clientId) {
                continue;
            }

            if (! $entry->allows($module)) {
                /* 404 rather than 403. See the note at the top of this class. */
                throw ResourceNotFound::of('Portal surface');
            }

            return new PortalReader($entry->clientId, $entry->clientName, $entry->isPreview);
        }

        throw ResourceNotFound::of('Portal');
    }

    /**
     * The clients this reader may reach, keyed by id.
     *
     * @return array<string, string>
     */
    private function clients(string $workspaceId, bool $isPreview): array
    {
        $visible = $this->visibility->visibleProjectIds();

        if ($visible === null && ! $isPreview) {
            /*
             * Workspace-wide visibility belongs to agency staff. A reader who somehow held it without the
             * permission to preview is not a client of anybody, and widening the loop to every client in the
             * workspace would be the wrong way to find that out.
             */
            return [];
        }

        return $this->portals->clientsVisibleTo($workspaceId, $visible);
    }
}
