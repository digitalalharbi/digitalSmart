<?php

declare(strict_types=1);

namespace App\Application\Advertising\Query;

use App\Application\Advertising\Contract\CampaignAssignmentRepository;
use App\Application\Advertising\DTO\CampaignOwnershipView;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Advertising\CampaignOwnership;

/**
 * Campaigns and the projects they belong to, with the unassigned ones findable.
 *
 * The review queue rule 9 asks for. Discovered data that nobody has placed must not disappear and must not
 * be silently bound; the only remaining option is that somebody can see it, which is this.
 */
final readonly class ListCampaignOwnership
{
    private const int PER_PAGE = 50;

    public function __construct(
        private CampaignAssignmentRepository $campaigns,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * @param  list<CampaignOwnership>  $only
     * @return array{campaigns: list<CampaignOwnershipView>, counts: array<string, int>, limit: int, offset: int}
     */
    public function __invoke(array $only = [], ?int $limit = null, int $offset = 0): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();
        $cap = max(1, min($limit ?? self::PER_PAGE, 200));
        $from = max(0, $offset);

        return [
            'campaigns' => $this->campaigns->page($workspaceId, $visible, $only, $cap, $from),
            /*
             * Over the whole population, not the page. "Eleven need assigning" is the figure somebody acts
             * on; "eleven on this page" is a figure that changes when they scroll.
             */
            'counts' => $this->campaigns->countByOwnership($workspaceId, $visible),
            'limit' => $cap,
            'offset' => $from,
        ];
    }
}
