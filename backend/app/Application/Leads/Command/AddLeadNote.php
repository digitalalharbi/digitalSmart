<?php

declare(strict_types=1);

namespace App\Application\Leads\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Leads\Contract\LeadRepository;
use App\Application\Leads\DTO\LeadView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Leads\Exception\InvalidLeadNote;

/**
 * Write down what happened.
 *
 * The least impressive use case in the leads module and the one that makes the rest of it usable: "rang twice,
 * no answer", "asked us to call after Eid", "wants the other model". Without it the only record of a
 * conversation is the stage it ended in, and a pipeline review becomes six people remembering differently.
 *
 * **Not audited**, deliberately, and this is the one place in the module that is not. The note *is* the record:
 * it goes into `lead_events` with its author and its time, which is what an audit entry would have said, and
 * writing both would be the same sentence in two places where only one of them is ever read.
 *
 * **No notification.** A note is not addressed to anybody; the people who need to see it are the people who open
 * the lead. A bell for every note is a bell that gets silenced, and then an assignment is missed.
 */
final readonly class AddLeadNote
{
    /** Long enough for what happened on a call, short enough that nobody pastes a transcript. */
    private const int MAX_LENGTH = 2_000;

    public function __construct(
        private LeadRepository $leads,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $leadId, string $note): LeadView
    {
        $this->guard->ensure(Permission::LeadsManage);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($this->leads->find($workspaceId, $this->visibility->visibleProjectIds(), $leadId) === null) {
            throw ResourceNotFound::of('Lead');
        }

        $trimmed = mb_substr(trim($note), 0, self::MAX_LENGTH);

        if ($trimmed === '') {
            /*
             * Nothing to record, so nothing is recorded. An empty note in the history is worse than no note: it
             * reads as somebody having tried to say something that was lost.
             */
            throw InvalidLeadNote::empty();
        }

        return $this->leads->addNote($workspaceId, $leadId, $trimmed, $this->actor->userId()->value);
    }
}
