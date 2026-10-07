<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Creative;

use App\Application\Creative\DTO\CreativeBoardView;
use App\Application\Creative\DTO\CreativeCommentView;
use App\Application\Creative\DTO\CreativeRequestView;
use App\Application\Creative\DTO\CreativeVersionView;
use App\Domain\Creative\AssetKind;
use App\Domain\Creative\CommentVisibility;
use App\Domain\Creative\CreativeSpec;
use App\Domain\Creative\CreativeState;
use App\Domain\Creative\SpecCheck;
use App\Domain\Creative\SpecProvenance;
use App\Domain\Creative\SpecReport;
use DateTimeImmutable;

final readonly class CreativeResource
{
    /**
     * The board's own vocabulary, so no client hard-codes the columns or the legal moves.
     *
     * The same rule the provider registry and the attribution catalogue follow. A front end carrying its own
     * copy of the transitions would eventually offer a move the server refuses, and the person dragging the
     * card would see an error instead of a disabled target.
     *
     * @return array<string, mixed>
     */
    public static function vocabulary(): array
    {
        return [
            'states' => array_map(static fn (CreativeState $state): array => [
                'state' => $state->value,
                'label' => $state->label(),
                'position' => $state->boardPosition(),
                'is_open' => $state->isOpen(),
                'is_waiting_on_review' => $state->isWaitingOnReview(),
                /*
                 * Published so a board can disable an illegal drop target rather than letting somebody
                 * discover the rule by being refused.
                 */
                'allowed_next' => array_map(
                    static fn (CreativeState $next): string => $next->value,
                    $state->allowedNext(),
                ),
                /*
                 * Whether a new version landing here sends the work back for review. The interface warns
                 * before an upload undoes an approval, rather than afterwards.
                 */
                'requires_re_review' => $state->requiresReReview(),
            ], CreativeState::board()),
            'kinds' => array_map(static fn (AssetKind $kind): array => [
                'kind' => $kind->value,
                'label' => $kind->label(),
                /* What can be checked about this kind, so a form does not ask for a duration on an image. */
                'measurable' => array_map(
                    static fn (\App\Domain\Creative\SpecRequirement $r): string => $r->value,
                    $kind->measurable(),
                ),
            ], AssetKind::cases()),
            'visibilities' => array_map(static fn (CommentVisibility $visibility): array => [
                'visibility' => $visibility->value,
                'label' => $visibility->label(),
                'is_visible_to_client' => $visibility->isVisibleToClient(),
            ], CommentVisibility::cases()),
        ];
    }

    /** @return array<string, mixed> */
    public static function board(CreativeBoardView $board): array
    {
        $columns = [];

        foreach (CreativeState::board() as $state) {
            $columns[] = [
                'state' => $state->value,
                'label' => $state->label(),
                'requests' => array_map(
                    static fn (CreativeRequestView $request): array => self::card($request, $board->now),
                    $board->column($state),
                ),
            ];
        }

        return [
            'columns' => $columns,
            /*
             * Three counts a producer actually acts on. Published rather than left to the interface, so the
             * board and any summary cannot disagree about what is open.
             */
            'open' => $board->openCount(),
            'waiting_on_review' => $board->waitingOnReview(),
            'overdue' => $board->overdueCount(),
        ];
    }

    /** @return array<string, mixed> */
    public static function request(CreativeRequestView $request, DateTimeImmutable $now): array
    {
        return [
            ...self::card($request, $now),
            'brief' => $request->brief,
            'versions' => array_map(
                static fn (CreativeVersionView $version): array => self::version($version),
                $request->versions,
            ),
            'comments' => array_map(
                static fn (CreativeCommentView $comment): array => self::comment($comment),
                $request->comments,
            ),
            /*
             * Whether the *newest* version is the approved one. Computed from the versions rather than read
             * off the state, because a request sitting in "approved" with a newer unapproved version is a
             * request whose state is stale — and this is how the interface notices.
             */
            'newest_is_approved' => $request->newestIsApproved(),
        ];
    }

    /**
     * Which placements have a recorded specification, and how old each is.
     *
     * @param  list<array{spec: CreativeSpec, is_stale: bool, age_days: int}>  $rows
     * @return list<array<string, mixed>>
     */
    public static function specs(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'provider' => $row['spec']->provider,
            'placement' => $row['spec']->placement,
            'kind' => $row['spec']->kind->value,
            'requirements' => array_keys($row['spec']->bounds),
            'source' => self::provenance($row['spec']->provenance),
            'is_stale' => $row['is_stale'],
            'age_days' => $row['age_days'],
        ], $rows);
    }

    /** @return array<string, mixed> */
    private static function card(CreativeRequestView $request, DateTimeImmutable $now): array
    {
        return [
            'id' => $request->id,
            'client_id' => $request->clientId,
            'client_name' => $request->clientName,
            'project_id' => $request->projectId,
            'project_name' => $request->projectName,
            'title' => $request->title,
            'state' => $request->state->value,
            'state_label' => $request->state->label(),
            'kind' => $request->kind->value,
            'kind_label' => $request->kind->label(),
            'provider' => $request->provider,
            'placement' => $request->placement,
            'owner_name' => $request->ownerName,
            'due_at' => $request->dueAt?->format(DATE_ATOM),
            'days_until_due' => $request->daysUntilDue($now),
            // Only while the work is open: a finished request is not late.
            'is_overdue' => $request->isOverdue($now),
            'cancelled_reason' => $request->cancelledReason,
            'state_changed_at' => $request->stateChangedAt?->format(DATE_ATOM),
            'created_at' => $request->createdAt->format(DATE_ATOM),
            'version_count' => count($request->versions),
        ];
    }

    /** @return array<string, mixed> */
    private static function version(CreativeVersionView $version): array
    {
        return [
            'id' => $version->id,
            'number' => $version->number,
            'kind' => $version->kind->value,
            'label' => $version->label,
            'storage_path' => $version->storagePath,
            'body' => $version->body,
            'uploaded_by_name' => $version->uploadedByName,
            'created_at' => $version->createdAt->format(DATE_ATOM),
            'measurements' => [
                /*
                 * Nulls travel as nulls. A width this product could not read is unknown, and a zero here
                 * would be reported by any interface as a very small image.
                 */
                'width_px' => $version->measurements->widthPx,
                'height_px' => $version->measurements->heightPx,
                'aspect_ratio' => $version->measurements->describeRatio(),
                'file_size_bytes' => $version->measurements->fileSizeBytes,
                'duration_seconds' => $version->measurements->durationSeconds,
                'format' => $version->measurements->format,
                'text_length' => $version->measurements->textLength,
                'unmeasured_reason' => $version->measurements->unmeasuredReason,
            ],
            'spec' => $version->spec === null ? null : self::spec($version->spec),
            'decision' => $version->decision?->value,
            'decision_label' => $version->decision?->label(),
            'decision_reason' => $version->decisionReason,
            'decided_by_name' => $version->decidedByName,
            'decided_at' => $version->decidedAt?->format(DATE_ATOM),
            'is_approved' => $version->isApproved(),
        ];
    }

    /** @return array<string, mixed> */
    private static function spec(SpecReport $report): array
    {
        return [
            'verdict' => $report->verdict->value,
            'verdict_label' => $report->verdict->label(),
            /*
             * Only a measured failure blocks a publish. Blocking on "not checked" would make this product's
             * own gaps into the user's problem, and they would learn to click past the warning.
             */
            'blocks_publishing' => $report->blocksPublishing(),
            'provider' => $report->provider,
            'placement' => $report->placement,
            'checks' => array_map(static fn (SpecCheck $check): array => [
                'requirement' => $check->requirement->value,
                'requirement_label' => $check->requirement->label(),
                'verdict' => $check->verdict->value,
                'verdict_label' => $check->verdict->label(),
                // Both numbers, so a producer can fix the file rather than ask somebody what failed.
                'measured' => $check->measured,
                'expected' => $check->expected,
                'detail' => $check->detail,
            ], $report->checks),
            'source' => $report->provenance === null ? null : self::provenance($report->provenance),
            'spec_age_days' => $report->specAgeDays,
        ];
    }

    /** @return array<string, mixed> */
    private static function provenance(SpecProvenance $provenance): array
    {
        return [
            /*
             * The page and the date, published with every verdict. A specification with no source is
             * folklore, and one nobody has read for a year is not evidence about today's platform.
             */
            'url' => $provenance->sourceUrl,
            'documented_at' => $provenance->documentedAt->format('Y-m-d'),
            'recorded_by' => $provenance->recordedBy,
            'note' => $provenance->note,
            'fresh_days' => SpecProvenance::FRESH_DAYS,
        ];
    }

    /** @return array<string, mixed> */
    private static function comment(CreativeCommentView $comment): array
    {
        return [
            'id' => $comment->id,
            'visibility' => $comment->visibility->value,
            'visibility_label' => $comment->visibility->label(),
            'is_visible_to_client' => $comment->visibility->isVisibleToClient(),
            'body' => $comment->body,
            'author_name' => $comment->authorName,
            'version_number' => $comment->versionNumber,
            'created_at' => $comment->createdAt->format(DATE_ATOM),
        ];
    }
}
