<?php

declare(strict_types=1);

namespace App\Application\Access\Command;

use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Access\DTO\NewAccessRequest;
use App\Application\Notifications\Command\Announce;
use App\Application\Notifications\DTO\NotificationDraft;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\AccessEventType;
use App\Domain\Access\AccessRequestKind;
use App\Domain\Access\AccessRequestNeed;
use App\Domain\Access\PreferredContact;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\Shared\ValueObject\ContactPoint;
use App\Domain\Workspace\WorkspaceId;
use Throwable;

/**
 * Somebody with no account asks for one, or sends an enquiry.
 *
 * Public, so it is deliberately incapable of telling the caller anything it would not tell a stranger: it answers the same
 * whether the request was stored, was a repeat, or was dropped because the installation takes none. A repeat is **kept** and
 * linked to the earlier one rather than dropped — a person who writes twice is saying something — and the only difference is
 * inside, where the two are marked as possible duplicates.
 *
 * It creates no account and sends nothing.
 */
final readonly class SubmitAccessRequest
{
    /** A second request from the same address or number inside this window is marked as a possible duplicate of the first. */
    private const string DUPLICATE_WINDOW = '-30 days';

    public function __construct(
        private AccessRequestRepository $requests,
        private IdentifierGenerator $ids,
        private Clock $clock,
        private AuditRecorder $audit,
        private Announce $announce,
    ) {}

    public function __invoke(
        AccessRequestKind $kind,
        string $name,
        string $email,
        ?string $company,
        ?string $phone,
        ?string $jobTitle,
        PreferredContact $preferred,
        ?AccessRequestNeed $need,
        ?string $message,
        string $locale,
    ): void {
        $workspaceId = $this->requests->intakeWorkspaceId();

        if ($workspaceId === null) {
            return;
        }

        $now = $this->clock->now();
        $address = mb_strtolower(trim($email));
        $phone = self::clean($phone);
        $phoneKey = ContactPoint::of(null, $phone)->phone;
        $settings = $this->requests->settings($workspaceId);
        $id = $this->ids->generate();

        $this->requests->add(new NewAccessRequest(
            id: $id,
            workspaceId: $workspaceId,
            kind: $kind,
            name: trim($name),
            email: $address,
            company: self::clean($company),
            phone: $phone,
            phoneKey: $phoneKey,
            jobTitle: self::clean($jobTitle),
            preferredContact: $preferred,
            need: $kind === AccessRequestKind::AccountAccess ? $need : null,
            message: self::clean($message),
            locale: $locale,
            ownerId: $settings->defaultOwnerId,
            possibleDuplicateOf: $this->requests->likelyDuplicate($workspaceId, $address, $phoneKey, $now->modify(self::DUPLICATE_WINDOW)),
            at: $now,
        ));

        $this->requests->addEvent($this->ids->generate(), $workspaceId, $id, AccessEventType::Created, null, null, ['kind' => $kind->value], $now);

        $this->audit->record(new AuditEntry(
            workspaceId: new WorkspaceId($workspaceId),
            actorId: null,
            action: AuditAction::AccessRequestCreated,
            auditableType: 'access_request',
            auditableId: $id,
            summary: 'A request was received',
            /* The kind and nothing about the person. */
            changes: ['kind' => $kind->value],
        ));

        try {
            ($this->announce)(new NotificationDraft(
                kind: NotificationKind::AccessRequestReceived,
                title: $kind === AccessRequestKind::AccountAccess ? 'A request for an account arrived' : 'An enquiry arrived',
                subjectId: $id,
                onlyUserId: $settings->defaultOwnerId,
                severity: NotificationSeverity::Info,
                payload: ['kind' => $kind->value],
                dedupeKey: 'access_request_received:'.$id,
            ), $workspaceId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function clean(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
