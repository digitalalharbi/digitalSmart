<?php

declare(strict_types=1);

namespace App\Application\Access\Command;

use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\AccessRequestView;
use App\Application\Access\Exception\AccessRequestRefused;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Application\Shared\Contract\OwnerDirectory;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\AccessEventType;
use App\Domain\Access\AccessRequestStatus;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use DateTimeImmutable;

/**
 * The everyday work on a request: give it to somebody, record that it was tried, write a note, say when to come back, move it.
 *
 * Every contact action records what a **person says they did**. Nothing here sends a message, so nothing here can mark one
 * delivered; delivery through a mail or messaging provider is a separate act that has no credentials yet and says so.
 *
 * Audit entries carry the kind of thing that happened and never a name, address, number or the text of a note.
 */
final readonly class WorkAccessRequest
{
    public function __construct(
        private AccessRequestRepository $requests,
        private OwnerDirectory $owners,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private AuditRecorder $audit,
        private Clock $clock,
        private IdentifierGenerator $ids,
    ) {}

    public function assign(string $id, ?string $ownerId): AccessRequestView
    {
        $request = $this->load($id);

        if ($ownerId !== null) {
            $eligible = array_map(
                static fn ($c): string => $c->id,
                $this->owners->peopleWhoCanSee($this->workspace(), null, Permission::UsersInvite),
            );

            if (! in_array($ownerId, $eligible, true)) {
                throw AccessRequestRefused::ownerCannotAnswer();
            }
        }

        $now = $this->clock->now();
        $this->requests->update($this->workspace(), $id, ['owner_id' => $ownerId], $now);
        $this->event($id, AccessEventType::OwnerAssigned, null, ['owner_id' => $ownerId], $now);
        $this->record(AuditAction::AccessRequestAssigned, $id, 'Gave a request to a colleague', ['assigned' => $ownerId !== null]);

        return $this->load($id);
    }

    /** @param AccessEventType $action one of `AccessEventType::contactActions()` */
    public function contact(string $id, AccessEventType $action, ?string $note): AccessRequestView
    {
        if (! in_array($action, AccessEventType::contactActions(), true)) {
            throw new AccessRequestRefused('That is not a contact action.', 'access_request_bad_action');
        }

        $request = $this->load($id);
        $this->ensureOpen($request);
        $now = $this->clock->now();

        $fields = ['last_contact_at' => self::at($now)];

        if ($request->firstAttemptAt === null) {
            $fields['first_attempt_at'] = self::at($now);
        }

        if ($action->isContact() && $request->firstContactedAt === null) {
            $fields['first_contacted_at'] = self::at($now);
        }

        /* Getting through moves a request that nobody had touched; trying alone does not say anything about where it stands. */
        if ($action->isContact() && $request->status === AccessRequestStatus::New) {
            $fields['status'] = AccessRequestStatus::Contacted->value;
        }

        $this->requests->update($this->workspace(), $id, $fields, $now);
        $this->event($id, $action, self::tidy($note), null, $now);
        $this->record(AuditAction::AccessRequestContacted, $id, 'Recorded a contact attempt', ['action' => $action->value]);

        return $this->load($id);
    }

    public function note(string $id, string $body): AccessRequestView
    {
        $this->load($id);
        $now = $this->clock->now();
        $this->event($id, AccessEventType::Note, trim($body), null, $now);
        $this->record(AuditAction::AccessRequestNoted, $id, 'Added a note to a request', []);

        return $this->load($id);
    }

    public function followUp(string $id, ?DateTimeImmutable $at): AccessRequestView
    {
        $request = $this->load($id);
        $this->ensureOpen($request);
        $now = $this->clock->now();
        $this->requests->update($this->workspace(), $id, ['next_follow_up_at' => $at === null ? null : self::at($at)], $now);
        $this->event($id, AccessEventType::FollowUpScheduled, null, ['at' => $at?->format(DATE_ATOM)], $now);
        $this->record(AuditAction::AccessRequestStatusChanged, $id, 'Set a follow-up', ['follow_up' => $at !== null]);

        return $this->load($id);
    }

    /** Move between the open statuses, or close a request. Approving and rejecting have their own use case. */
    public function move(string $id, AccessRequestStatus $to): AccessRequestView
    {
        if ($to === AccessRequestStatus::Approved || $to === AccessRequestStatus::Rejected) {
            throw new AccessRequestRefused('Approve or reject a request on its own.', 'access_request_decide_separately');
        }

        $request = $this->load($id);

        if (! $request->status->canMoveTo($to)) {
            throw AccessRequestRefused::cannotMove($request->status, $to);
        }

        $now = $this->clock->now();
        $this->requests->update($this->workspace(), $id, ['status' => $to->value], $now);
        $this->event($id, AccessEventType::StatusChanged, null, ['from' => $request->status->value, 'to' => $to->value], $now);
        $this->record(AuditAction::AccessRequestStatusChanged, $id, 'Changed the status of a request', ['from' => $request->status->value, 'to' => $to->value]);

        return $this->load($id);
    }

    private function load(string $id): AccessRequestView
    {
        $this->guard->ensure(Permission::UsersInvite);

        return $this->requests->find($this->workspace(), $id) ?? throw ResourceNotFound::of('Request');
    }

    private function ensureOpen(AccessRequestView $request): void
    {
        if ($request->status === AccessRequestStatus::Rejected || $request->status === AccessRequestStatus::Closed) {
            throw AccessRequestRefused::closed();
        }
    }

    private function workspace(): string
    {
        return $this->actor->workspaceId()->value;
    }

    /** @param array<string, mixed>|null $meta */
    private function event(string $id, AccessEventType $type, ?string $body, ?array $meta, DateTimeImmutable $at): void
    {
        $this->requests->addEvent($this->ids->generate(), $this->workspace(), $id, $type, $this->actor->userId()->value, $body, $meta, $at);
    }

    /** @param array<string, mixed> $changes */
    private function record(AuditAction $action, string $id, string $summary, array $changes): void
    {
        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: $action,
            auditableType: 'access_request',
            auditableId: $id,
            summary: $summary,
            changes: $changes,
        ));
    }

    private static function tidy(?string $note): ?string
    {
        return $note === null || trim($note) === '' ? null : trim($note);
    }

    private static function at(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:sP');
    }
}
