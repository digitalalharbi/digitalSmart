<?php

declare(strict_types=1);

namespace App\Application\Leads\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Contract\LeadOwnerDirectory;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadOwnerCandidate;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * Who this lead can be put in the name of.
 *
 * Its own narrow query rather than the workspace member list, for two reasons. The member list needs
 * `users.view`, which a media buyer working a pipeline has no reason to hold; and it would offer colleagues who
 * cannot open this client's leads, so the picker would be a list of choices the server then refuses.
 *
 * Ids and names only. A picker does not need email addresses, and the people in it did not agree to have theirs
 * listed to whoever is working a pipeline.
 */
final readonly class ListLeadOwners
{
    public function __construct(
        private LeadRepository $leads,
        private LeadOwnerDirectory $owners,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<LeadOwnerCandidate> */
    public function __invoke(string $leadId): array
    {
        $this->guard->ensure(Permission::LeadsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $lead = $this->leads->find($workspaceId, $this->visibility->visibleProjectIds(), $leadId)
            ?? throw ResourceNotFound::of('Lead');

        return $this->owners->peopleWhoCanSee($workspaceId, $lead->clientId);
    }
}
