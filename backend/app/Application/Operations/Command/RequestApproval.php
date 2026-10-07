<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\ApprovalRepository;
use App\Application\Operations\DTO\ApprovalInput;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Operations\Exception\InvalidApprovalRequest;
use App\Domain\Operations\Exception\InvalidTaskSubject;

/**
 * Ask for permission to change something.
 *
 * Requesting is **separate from deciding**, and a requester needs only `approvals.request`. That separation is
 * the entire value of the module: a media buyer who could approve their own spend change has an approvals
 * screen rather than an approvals process.
 *
 * Three refusals:
 *
 *  - **A proposal that says nothing.** An empty `proposal` is refused: a decider has to be able to read what
 *    they are agreeing to, and "approve this" with no content is a signature on a blank page.
 *  - **A request with no rationale.** Refused here and by a CHECK. It is the only record of why anybody wanted
 *    this, and the first question asked afterwards.
 *  - **Half a subject link**, like a task.
 */
final readonly class RequestApproval
{
    public function __construct(
        private ApprovalRepository $approvals,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(ApprovalInput $input): ApprovalView
    {
        $this->guard->ensure(Permission::ApprovalsRequest);

        if (! $input->hasWholeSubject()) {
            throw InvalidTaskSubject::incomplete();
        }

        if (trim($input->rationale) === '') {
            throw InvalidApprovalRequest::rationaleRequired();
        }

        if ($input->proposal === []) {
            throw InvalidApprovalRequest::proposalRequired();
        }

        $workspaceId = $this->actor->workspaceId()->value;
        $visible = $this->visibility->visibleProjectIds();

        if ($input->projectId !== null && $visible !== null && ! in_array($input->projectId, $visible, true)) {
            throw ResourceNotFound::of('Project');
        }

        if ($input->supersedesId !== null) {
            /*
             * The request being replaced has to be one the actor can see. Otherwise a reference becomes a way
             * to learn that a request exists on a client they have no access to.
             */
            $this->approvals->find($workspaceId, $visible, $input->supersedesId)
                ?? throw ResourceNotFound::of('Approval request');
        }

        $request = $this->approvals->request($workspaceId, $input, $this->actor->userId()->value);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ApprovalRequested,
            auditableType: 'approval_request',
            auditableId: $request->id,
            summary: sprintf('Requested approval to %s: "%s"', mb_strtolower($request->kind->label()), $request->title),
            changes: [
                'kind' => $request->kind->value,
                /*
                 * Recorded on the entry, because "who asked to change live spend" is the question an audit of
                 * this module is for, and reading it back from a joined table a year later is a worse answer.
                 */
                'touches_live_spend' => $request->kind->touchesLiveSpend(),
                'fingerprint' => $request->fingerprint,
                'supersedes' => $request->supersedesId,
                'client_id' => $request->clientId,
            ],
        ));

        return $request;
    }
}
