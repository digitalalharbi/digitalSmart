<?php

declare(strict_types=1);

namespace App\Application\Operations\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\AttachmentStore;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\DTO\AttachmentView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;

/**
 * One attachment's bytes, for somebody who may have them.
 *
 * **Every read goes through here**, which is why the store offers no public URL. A signed bucket link would be a
 * second place the audience rule has to hold, and the first time the two disagreed a client would be reading an
 * internal file.
 *
 * The visibility decision is made in SQL by the repository, with the same `clientFacing` flag every other read
 * uses. Not by this class comparing an audience afterwards: a filter applied after the row is in hand is a filter
 * somebody eventually forgets, and the consequence here is a competitor's rate card in a client's browser.
 *
 * A missing file is a **404**, not a 500. A record pointing at bytes that are gone is a real state — a restore
 * from a database backup without the disk behind it produces exactly that — and the honest answer is that this
 * file is not there rather than a server error that invites a retry.
 */
final readonly class ReadAttachment
{
    public function __construct(
        private ClientRequestRepository $requests,
        private AttachmentStore $store,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    /** @return array{attachment: AttachmentView, contents: string} */
    public function __invoke(string $attachmentId): array
    {
        $this->guard->ensure(Permission::ClientRequestsSubmit);

        $isAgency = $this->guard->allows(Permission::ClientRequestsView);

        $attachment = $this->requests->attachment(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $attachmentId,
            ! $isAgency,
        ) ?? throw ResourceNotFound::of('Attachment');

        $contents = $this->store->read($attachment->storedName);

        if ($contents === null) {
            throw ResourceNotFound::of('Attachment');
        }

        /*
         * Recorded, because a download is a read of somebody else's file. Not the bytes and not the checksum —
         * who took a copy, and when.
         */
        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ClientRequestAttachmentRead,
            auditableType: 'client_request_attachment',
            auditableId: $attachment->id,
            summary: sprintf('Downloaded "%s"', $attachment->originalName),
            changes: [
                'audience' => $attachment->audience->value,
                'sniffed_type' => $attachment->type->value,
                'by_client' => ! $isAgency,
            ],
        ));

        return ['attachment' => $attachment, 'contents' => $contents];
    }
}
