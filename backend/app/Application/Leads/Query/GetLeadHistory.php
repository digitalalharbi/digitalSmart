<?php

declare(strict_types=1);

namespace App\Application\Leads\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadHistoryEntry;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * What has happened to this lead.
 *
 * **`LeadsView`, not `LeadsManage`.** Reading the history is how a pipeline is reviewed, and a media buyer who
 * may read the pipeline — because cost per *qualified* lead is the only honest measure of their work — has to be
 * able to see that somebody rang twice and got no answer. Writing the history needs `LeadsManage`; reading it
 * does not.
 */
final readonly class GetLeadHistory
{
    /** Enough for a pipeline review. Past this, somebody is reading a transcript rather than a history. */
    private const int LIMIT = 50;

    public function __construct(
        private LeadRepository $leads,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<LeadHistoryEntry> */
    public function __invoke(string $leadId): array
    {
        $this->guard->ensure(Permission::LeadsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        if ($this->leads->find($workspaceId, $visible, $leadId) === null) {
            throw ResourceNotFound::of('Lead');
        }

        return $this->leads->historyOf($workspaceId, $visible, $leadId, self::LIMIT);
    }
}
