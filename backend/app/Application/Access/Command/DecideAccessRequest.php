<?php

declare(strict_types=1);

namespace App\Application\Access\Command;

use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\AccessRequestView;
use App\Application\Access\Exception\AccessRequestRefused;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\AccessEventType;
use App\Domain\Access\AccessRequestStatus;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;

/**
 * Say yes or no to a request.
 *
 * **Approving creates nobody.** It records that the answer is yes and tells the people who answer requests; an account exists
 * only after an invitation is made and the invited person accepts it (`ManageAccessInvitation`). Only a request for an account
 * can be approved — an enquiry is answered, not approved.
 *
 * **Rejecting needs a reason, and the reason is internal.** A message for the person is a separate, optional field; nothing here
 * sends it, and nothing public ever shows either.
 */
final readonly class DecideAccessRequest
{
    public function __construct(
        private AccessRequestRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private Clock $clock,
        private IdentifierGenerator $ids,
        private Announce $announce,
    ) {}

    public function approve(string $id, ?string $note): AccessRequestView
    {
        $request = $this->load($id);

        if (! $request->kind->canBeApproved()) {
            throw AccessRequestRefused::notAnAccountRequest();
        }

        if (! $request->status->canMoveTo(AccessRequestStatus::Approved)) {
            throw AccessRequestRefused::cannotMove($request->status, AccessRequestStatus::Approved);
        }

        $now = $this->clock->now();
        $this->requests->update($this->workspace(), $id, [
            'status' => AccessRequestStatus::Approved->value,
            'reviewed_by' => $this->actor->userId()->value,
            'reviewed_at' => $now->format('Y-m-d H:i:sP'),
            'review_note' => self::tidy($note),
        ], $now);
        $this->requests->addEvent($this->ids->generate(), $this->workspace(), $id, AccessEventType::Approved, $this->actor->userId()->value, self::tidy($note), null, $now);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::AccessRequestApproved,
            auditableType: 'access_request',
            auditableId: $id,
            summary: 'Approved a request for an account',
            changes: ['from' => $request->status->value, 'to' => AccessRequestStatus::Approved->value],
        ));

        ($this->announce)(new NotificationDraft(
            kind: NotificationKind::AccessRequestApproved,
            title: 'A request for an account was approved',
            subjectId: $id,
            onlyUserId: $request->ownerId,
            severity: NotificationSeverity::Success,
            dedupeKey: 'access_request_approved:'.$id,
        ));

        return $this->load($id);
    }

    public function reject(string $id, string $reason, ?string $messageForThePerson): AccessRequestView
    {
        $request = $this->load($id);

        if (trim($reason) === '') {
            throw AccessRequestRefused::reasonRequired();
        }

        if (! $request->status->canMoveTo(AccessRequestStatus::Rejected)) {
            throw AccessRequestRefused::cannotMove($request->status, AccessRequestStatus::Rejected);
        }

        $now = $this->clock->now();
        $this->requests->update($this->workspace(), $id, [
            'status' => AccessRequestStatus::Rejected->value,
            'reviewed_by' => $this->actor->userId()->value,
            'reviewed_at' => $now->format('Y-m-d H:i:sP'),
            'review_note' => trim($reason),
            'client_message' => self::tidy($messageForThePerson),
        ], $now);
        $this->requests->addEvent($this->ids->generate(), $this->workspace(), $id, AccessEventType::Rejected, $this->actor->userId()->value, trim($reason), null, $now);

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::AccessRequestRejected,
            auditableType: 'access_request',
            auditableId: $id,
            summary: 'Rejected a request',
            /* That it was rejected; the reason is on the request, where fewer people read than the trail. */
            changes: ['from' => $request->status->value, 'to' => AccessRequestStatus::Rejected->value],
        ));

        return $this->load($id);
    }

    private function load(string $id): AccessRequestView
    {
        $this->guard->ensure(Permission::UsersInvite);

        return $this->requests->find($this->workspace(), $id) ?? throw ResourceNotFound::of('Request');
    }

    private function workspace(): string
    {
        return $this->actor->workspaceId()->value;
    }

    private static function tidy(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
