<?php

declare(strict_types=1);

namespace App\Application\Creative\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Creative\Contract\CreativeRepository;
use App\Application\Creative\DTO\CreativeRequestView;
use App\Application\Creative\DTO\VersionInput;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Creative\CreativeState;

/**
 * Add a version, and send the work back for review if review has already happened.
 *
 * That second half is the rule this whole module exists to enforce. **A client approved a file, not a
 * request.** A tool that left the request in the approved state while a new cut appeared would have an
 * approval trail saying the newest work was signed off when nobody had seen it — and the agency would find
 * out when the client did.
 *
 * So uploading onto an approved, rejected or in-review request moves it back to review, and the audit entry
 * records that it happened rather than leaving a state change nobody can account for.
 */
final readonly class AddCreativeVersion
{
    public function __construct(
        private CreativeRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(string $requestId, VersionInput $input): CreativeRequestView
    {
        $this->guard->ensure(Permission::CreativeManage);

        $workspaceId = $this->actor->workspaceId()->value;

        $current = $this->requests->find($workspaceId, $this->visibility->visibleProjectIds(), $requestId)
            ?? throw ResourceNotFound::of('Creative request');

        $withVersion = $this->requests->addVersion(
            $workspaceId,
            $requestId,
            $input,
            $this->actor->userId()->value,
        );

        $version = $withVersion->latestVersion();

        $returned = false;

        if ($current->state->requiresReReview() && $current->state !== CreativeState::InReview) {
            /*
             * Back to review, which is the only honest state for a request whose newest version nobody has
             * looked at. The earlier approval stays in the trail attached to the version it was about.
             */
            $withVersion = $this->requests->move($workspaceId, $requestId, CreativeState::InProduction, null);
            $withVersion = $this->requests->move($workspaceId, $requestId, CreativeState::InReview, null);
            $returned = true;
        }

        $this->audit->record(new AuditEntry(
            workspaceId: $this->actor->workspaceId(),
            actorId: $this->actor->userId(),
            action: AuditAction::CreativeVersionAdded,
            auditableType: 'creative_request',
            auditableId: $requestId,
            summary: sprintf('Added version %d to "%s"', $version === null ? 0 : $version->number, $withVersion->title),
            changes: [
                'version' => $version?->number,
                'kind' => $input->kind->value,
                /*
                 * Recorded, because "this version was never checked" is a question somebody asks when a
                 * platform rejects an upload, and the answer is usually that nothing could be measured.
                 */
                'measured' => $input->measurements->unmeasuredReason === null,
                'returned_for_review' => $returned,
                'previous_state' => $current->state->value,
            ],
        ));

        return $withVersion;
    }
}
