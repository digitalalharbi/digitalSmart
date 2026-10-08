<?php

declare(strict_types=1);

namespace App\Application\Operations\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Operations\Contract\AttachmentStore;
use App\Application\Operations\Contract\ClientRequestRepository;
use App\Application\Operations\DTO\ClientRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Operations\AttachmentType;
use App\Domain\Operations\Exception\InvalidAttachment;
use App\Domain\Operations\MessageAudience;

/**
 * Put a file on a client request.
 *
 * **The type is decided by the file's own bytes.** Not its extension, which the uploader chose, and not the
 * `Content-Type` it arrived with, which the uploader also chose. Trusting either means accepting a file called
 * `brief.png` that is an HTML document and serving it from this product's origin — which is a script running as
 * whoever opens it. The sniffed type is what is stored, what the download serves, and what a CHECK constrains.
 *
 * **The audience rule is the messages' rule.** A client's upload is forced to `Client`, whatever was sent: their
 * own file must reach the person they are talking to, and a file of theirs filed as internal would be hidden
 * from the person who sent it. An agency member chooses, every time, with no default — an internal screenshot
 * reaching the client it is about ends a relationship, and a default is how it happens.
 *
 * **There is no virus scanner**, and that is stated rather than implied. No scanning service is attached to this
 * repository, so this product's defences are the allow-list, the size limit, a stored name it generated itself,
 * and a download that is always an attachment and never inline. A file is treated as somebody else's file: it is
 * never executed, never served into this origin, and never trusted because of its name. `docs/OPERATIONS.md`
 * says so where an operator will read it.
 */
final readonly class AttachToClientRequest
{
    /**
     * Five megabytes.
     *
     * Chosen from what this feature is for: a screenshot of the problem, a spreadsheet of the new product lines,
     * a PDF of a brief. A video is not one of those, and "send a link" is an answer somebody can act on — while a
     * limit generous enough for video is a limit that fills a disk nobody is watching.
     */
    public const int MAX_BYTES = 5 * 1_048_576;

    /** As many as a conversation can hold before it stops being a conversation. */
    public const int MAX_PER_REQUEST = 10;

    public function __construct(
        private ClientRequestRepository $requests,
        private AttachmentStore $store,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    /**
     * @param  string  $contents  the raw bytes, already read
     * @param  string  $sniffedType  what a content sniffer reported, never a header or an extension
     */
    public function __invoke(
        string $requestId,
        MessageAudience $audience,
        string $originalName,
        string $contents,
        string $sniffedType,
    ): ClientRequestView {
        $this->guard->ensure(Permission::ClientRequestsSubmit);

        $workspaceId = $this->actor->workspaceId()->value;
        $isAgency = $this->guard->allows(Permission::ClientRequestsView);

        $current = $this->requests->find(
            $workspaceId,
            $this->visibility->visibleProjectIds(),
            $requestId,
            ! $isAgency,
        ) ?? throw ResourceNotFound::of('Client request');

        /*
         * A settled request takes no more files. Not a permission problem: the thread is finished, and adding to
         * a delivered request is how a second piece of work ends up with no record of having been asked for.
         */
        if (! $current->state->isOpen()) {
            throw InvalidAttachment::requestSettled();
        }

        $bytes = strlen($contents);

        if ($bytes === 0) {
            throw InvalidAttachment::empty();
        }

        if ($bytes > self::MAX_BYTES) {
            throw InvalidAttachment::tooLarge($bytes, self::MAX_BYTES);
        }

        $type = AttachmentType::fromSniffedType($sniffedType);

        if ($type === null) {
            throw InvalidAttachment::typeNotAccepted($sniffedType);
        }

        if ($this->requests->countAttachments($workspaceId, $requestId) >= self::MAX_PER_REQUEST) {
            throw InvalidAttachment::tooMany(self::MAX_PER_REQUEST);
        }

        /* Forced for a client, exactly as a message is. See the note above, and the CHECK that says the same. */
        $audienceUsed = $isAgency ? $audience : MessageAudience::Client;

        $storedName = $this->store->put($workspaceId, $contents, $type->extension());

        $attached = $this->requests->attach(
            $workspaceId,
            $requestId,
            $audienceUsed,
            /* Kept for display only. A path built from this would be a path traversal. */
            $this->readableName($originalName),
            $storedName,
            $type,
            $bytes,
            hash('sha256', $contents),
            $this->actor->userId()->value,
            ! $isAgency,
            $this->clock->now(),
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::ClientRequestAttached,
            auditableType: 'client_request',
            auditableId: $requestId,
            summary: sprintf('Attached a file to "%s"', $attached->title),
            changes: [
                /*
                 * The audience, the sniffed type and the size — the three fields an argument about a leaked or a
                 * malicious file turns on. The bytes are not copied here: an audit log is not a second place a
                 * client's files live.
                 */
                'audience' => $audienceUsed->value,
                'sniffed_type' => $type->value,
                'byte_size' => $bytes,
                'from_client' => ! $isAgency,
            ],
        ));

        return $attached;
    }

    /**
     * A filename safe to show, which is a different question from a filename safe to use.
     *
     * Nothing is built from this — not a path, not a type, not the stored name. It is trimmed, shortened and
     * stripped of control characters so a reader sees what they sent rather than a line of terminal escapes.
     */
    private function readableName(string $name): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', '', trim($name)) ?? '';
        $clean = str_replace(['/', '\\'], '-', $clean);

        return $clean === '' ? 'file' : mb_substr($clean, 0, 120);
    }
}
