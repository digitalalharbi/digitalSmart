<?php

declare(strict_types=1);

namespace App\Application\Attention\Source;

use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Operations\Contract\ApprovalFilter;
use App\Application\Operations\DTO\ApprovalView;
use App\Application\Operations\Query\GetApprovalQueue;
use App\Application\Shared\Contract\Clock;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;
use App\Domain\Operations\ApprovalAge;

/**
 * Decisions nobody has made, and decisions nobody has carried out.
 *
 * Two different failures, and the second is the one the approvals module exists for. A pending request
 * is somebody waiting. An outstanding one has been *approved* and not acted on — a client was told a
 * change would happen and it has not. That is worse than waiting, so it enters the queue at any age while
 * a pending request has to have been sitting a week.
 *
 * Younger pending requests are left out on purpose. A queue moving normally is not a problem, and putting
 * every open approval on somebody's home screen is how the screen stops being read.
 */
final readonly class ApprovalAttentionSource implements AttentionSource
{
    public function __construct(
        private GetApprovalQueue $queue,
        private Clock $clock,
    ) {}

    public function stream(): AttentionStream
    {
        return AttentionStream::Approvals;
    }

    /** @return list<AttentionItem> */
    public function items(): array
    {
        $queue = ($this->queue)(ApprovalFilter::everything());
        $now = $this->clock->now();
        $items = [];

        foreach ($queue->pending as $approval) {
            $age = $approval->age($now);

            if ($age !== ApprovalAge::Week && $age !== ApprovalAge::Stale) {
                continue;
            }

            $items[] = $this->toItem(
                $approval,
                $age === ApprovalAge::Stale ? AttentionSeverity::Critical : AttentionSeverity::NeedsAttention,
                sprintf('Waiting for a decision: %s', $approval->title),
                'APPROVAL_WAITING',
            );
        }

        foreach ($queue->outstanding as $approval) {
            $age = $approval->age($now);

            $items[] = $this->toItem(
                $approval,
                $age === ApprovalAge::Stale || $age === ApprovalAge::Week
                    ? AttentionSeverity::Critical
                    : AttentionSeverity::NeedsAttention,
                sprintf('Approved, and not yet carried out: %s', $approval->title),
                'APPROVAL_NOT_CARRIED_OUT',
            );
        }

        return $items;
    }

    private function toItem(
        ApprovalView $approval,
        AttentionSeverity $severity,
        string $message,
        string $code,
    ): AttentionItem {
        return new AttentionItem(
            reasonCode: $code,
            reasonParams: ['title' => $approval->title],
            id: 'approval:'.$approval->id,
            stream: AttentionStream::Approvals,
            severity: $severity,
            message: $message,
            entityKind: $approval->kind->label(),
            entityLabel: $approval->clientName ?? $approval->title,
            /* Whoever asked is who chases it; this module has no assignee of its own. */
            owner: $approval->requestedByName,
            since: $approval->waitingSince() ?? $approval->createdAt,
            clientId: $approval->clientId,
            clientName: $approval->clientName,
            projectId: $approval->projectId,
            projectName: $approval->projectName,
        );
    }
}
