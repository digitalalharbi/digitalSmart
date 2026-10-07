<?php

declare(strict_types=1);

namespace App\Application\Creative\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Creative\Contract\CreativeRepository;
use App\Application\Creative\Contract\CreativeSpecRegistry;
use App\Application\Creative\DTO\CreativeCommentView;
use App\Application\Creative\DTO\CreativeRequestView;
use App\Application\Creative\DTO\CreativeVersionView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;
use App\Domain\Creative\CommentVisibility;
use App\Domain\Creative\SpecReport;
use DateTimeImmutable;

/**
 * One request, with every version checked against the placement's recorded specification.
 *
 * The check happens here rather than being stored, and that is deliberate: a stored verdict would be a
 * confident claim citing a page that somebody may since have re-read. Computing it on request means the
 * answer always reflects the newest recording of what the platform asks for, and carries that recording's
 * date.
 *
 * Where no specification is recorded — which is most placements, because the platforms do not publish them
 * in a form a program can read — the report says `no_spec` rather than passing. See docs/CREATIVE.md.
 */
final readonly class GetCreativeRequest
{
    public function __construct(
        private CreativeRepository $requests,
        private CreativeSpecRegistry $specs,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(string $requestId, bool $clientFacingOnly = false): CreativeRequestView
    {
        $this->guard->ensure(Permission::CreativeView);

        $request = $this->requests->find(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $requestId,
        ) ?? throw ResourceNotFound::of('Creative request');

        return new CreativeRequestView(
            id: $request->id,
            clientId: $request->clientId,
            clientName: $request->clientName,
            title: $request->title,
            state: $request->state,
            kind: $request->kind,
            createdAt: $request->createdAt,
            projectId: $request->projectId,
            projectName: $request->projectName,
            brief: $request->brief,
            provider: $request->provider,
            placement: $request->placement,
            dueAt: $request->dueAt,
            ownerName: $request->ownerName,
            cancelledReason: $request->cancelledReason,
            stateChangedAt: $request->stateChangedAt,
            versions: $this->checked($request),
            comments: $this->visible($request->comments, $clientFacingOnly),
        );
    }

    /**
     * Every version with its specification report attached.
     *
     * @return list<CreativeVersionView>
     */
    private function checked(CreativeRequestView $request): array
    {
        $now = $this->clock->now();
        $versions = [];

        foreach ($request->versions as $version) {
            $versions[] = new CreativeVersionView(
                id: $version->id,
                number: $version->number,
                kind: $version->kind,
                measurements: $version->measurements,
                createdAt: $version->createdAt,
                label: $version->label,
                storagePath: $version->storagePath,
                body: $version->body,
                uploadedByName: $version->uploadedByName,
                spec: $this->report($request, $version, $now),
                decision: $version->decision,
                decisionReason: $version->decisionReason,
                decidedByName: $version->decidedByName,
                decidedAt: $version->decidedAt,
            );
        }

        return $versions;
    }

    private function report(
        CreativeRequestView $request,
        CreativeVersionView $version,
        DateTimeImmutable $now,
    ): SpecReport {
        if ($request->provider === null || $request->placement === null) {
            /*
             * Nobody has said where this is going, so there is nothing to check it against. A separate
             * statement from "we have no spec for that placement", and both honest.
             */
            return SpecReport::noSpec($request->provider ?? 'unassigned', $request->placement ?? 'unassigned');
        }

        $spec = $this->specs->find($request->provider, $request->placement, $version->kind);

        return $spec === null
            ? SpecReport::noSpec($request->provider, $request->placement)
            : $spec->check($version->measurements, $now);
    }

    /**
     * The comments the caller may see.
     *
     * Filtered here rather than trusted to a caller, because an agency's internal note on a client's
     * creative reaching that client ends a relationship — and "the portal forgot the filter" is how it
     * happens.
     *
     * @param  list<CreativeCommentView>  $comments
     * @return list<CreativeCommentView>
     */
    private function visible(array $comments, bool $clientFacingOnly): array
    {
        if (! $clientFacingOnly) {
            return $comments;
        }

        return array_values(array_filter(
            $comments,
            static fn (CreativeCommentView $comment): bool => $comment->visibility === CommentVisibility::Client,
        ));
    }
}
