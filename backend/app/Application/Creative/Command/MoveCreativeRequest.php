<?php

declare(strict_types=1);

namespace App\Application\Creative\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Creative\Contract\CreativeRepository;
use App\Application\Creative\DTO\CreativeRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Creative\CreativeState;
use App\Domain\Creative\Exception\InvalidCreativeTransition;

/**
 * Move work along the board.
 *
 * The transition is checked by the domain, which refuses an illegal move and names the legal ones. Two
 * moves are refused here rather than there, because they need more than the two states to decide:
 *
 *  - **Approving through the board.** Approval attaches to a version and carries a decider, so it goes
 *    through `DecideOnVersion`. Letting a drag on a Kanban column approve a client's creative would put an
 *    approval in the trail with nobody's name on it.
 *  - **Cancelling without a reason.** The database refuses it too; this refuses it with a sentence.
 */
final readonly class MoveCreativeRequest
{
    public function __construct(
        private CreativeRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $requestId, CreativeState $state, ?string $reason = null): CreativeRequestView
    {
        $this->guard->ensure(Permission::CreativeManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->requests->find($workspaceId, $this->visibility->visibleProjectIds(), $requestId)
            ?? throw ResourceNotFound::of('Creative request');

        if ($state === CreativeState::Approved) {
            /*
             * Not through the board. An approval needs a version and a decider, and a column drag has
             * neither — so the trail would record that somebody approved something, without saying who
             * approved what.
             */
            throw InvalidCreativeTransition::nothingToApprove($current->state);
        }

        if ($state === CreativeState::ChangesRequested) {
            // Same reasoning: sending work back is a decision about a version, with a reason.
            throw InvalidCreativeTransition::changesNeedAReason($current->state);
        }

        $current->state->assertCanMoveTo($state);

        if ($state === CreativeState::Cancelled && ($reason === null || trim($reason) === '')) {
            throw InvalidCreativeTransition::changesNeedAReason($current->state);
        }

        $moved = $this->requests->move($workspaceId, $requestId, $state, $reason);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::CreativeMoved,
            auditableType: 'creative_request',
            auditableId: $requestId,
            summary: sprintf('Moved "%s" to %s', $moved->title, $state->label()),
            // Both sides, so "who moved this back into production" is answerable from the record.
            changes: ['from' => $current->state->value, 'to' => $state->value, 'reason' => $reason],
        ));

        return $moved;
    }
}
