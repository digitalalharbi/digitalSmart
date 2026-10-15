<?php

declare(strict_types=1);

namespace App\Application\Leads\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadFilter;
use App\Application\Leads\DTO\LeadView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;
use App\Domain\Leads\LeadStage;
use DateTimeImmutable;

/** The leads a person may work on. */
final readonly class ListLeads
{
    public function __construct(
        private LeadRepository $leads,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /**
     * @param  list<LeadStage>  $stages
     * @return list<LeadView>
     */
    public function __invoke(
        ?string $clientId = null,
        array $stages = [],
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $until = null,
        int $limit = 100,
        ?LeadFilter $filter = null,
    ): array {
        $this->guard->ensure(Permission::LeadsView);

        return $this->leads->visibleTo(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $clientId,
            $stages,
            $from,
            $until,
            $limit,
            $filter === null ? null : new LeadFilter(
                $filter->scope, $this->actor->userId()->value, $filter->projectId, $filter->ownerId, $filter->sourceKind, $filter->campaign,
            ),
        );
    }
}
