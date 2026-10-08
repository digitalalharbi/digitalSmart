<?php

declare(strict_types=1);

namespace App\Application\Leads\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadDuplicateRow;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * The leads that might be the same person as this one.
 *
 * **Exact matches on a normalised contact point, and nothing else.** No fuzzy names, no "same city and similar
 * phone", no score. The journey read model already states the reason in the one place it would be most tempting
 * to relax: a fuzzy name match merges two people called Mohammed Al-Harbi into one record that nobody can
 * unpick afterwards. A merge is not reversible by any control this product offers, so the only evidence it will
 * act on is evidence that cannot be wrong about identity.
 *
 * **No time window here**, unlike the deduplication that happens when a lead arrives. That window exists to
 * decide automatically, and the risk it manages is this product merging two enquiries nobody asked it to. This
 * query is a human asking the opposite question — *are these the same person?* — and an enquiry from the same
 * phone six months ago is exactly what they want to see.
 *
 * Needs `LeadsManage` rather than `LeadsView`: the list exists to be acted on, and it carries the contact
 * details that make the comparison possible.
 */
final readonly class FindLeadDuplicates
{
    /** Enough to choose from; more than this and the contact point is not identifying anybody. */
    private const int LIMIT = 10;

    public function __construct(
        private LeadRepository $leads,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return list<LeadDuplicateRow> */
    public function __invoke(string $leadId): array
    {
        $this->guard->ensure(Permission::LeadsManage);

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        if ($this->leads->find($workspaceId, $visible, $leadId) === null) {
            throw ResourceNotFound::of('Lead');
        }

        return $this->leads->duplicatesOf($workspaceId, $visible, $leadId, self::LIMIT);
    }
}
