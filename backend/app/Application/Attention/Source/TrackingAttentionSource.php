<?php

declare(strict_types=1);

namespace App\Application\Attention\Source;

use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Measurement\Query\GetTrackingHealth;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;
use App\Domain\Measurement\TrackingSeverity;

/**
 * What measurement is currently getting wrong.
 *
 * The sentence comes from the issue itself. The code that found a silent conversion already described it
 * accurately, and re-describing it here would mean two descriptions of one fact with nothing keeping them
 * in step.
 *
 * Informational notes are left out. They belong on the tracking page, where somebody is already looking
 * at measurement; a queue that includes them is a queue nobody finishes.
 */
final readonly class TrackingAttentionSource implements AttentionSource
{
    public function __construct(private GetTrackingHealth $health) {}

    public function stream(): AttentionStream
    {
        return AttentionStream::Tracking;
    }

    /** @return list<AttentionItem> */
    public function items(): array
    {
        $items = [];

        foreach (($this->health)()->issues as $index => $issue) {
            $severity = $this->severity($issue->severity);

            if ($severity === null) {
                continue;
            }

            $items[] = new AttentionItem(
                id: sprintf('tracking:%s:%s', $issue->code, $issue->subject ?? (string) $index),
                stream: AttentionStream::Tracking,
                severity: $severity,
                message: $issue->summary,
                entityKind: 'Tracking',
                entityLabel: $issue->subject ?? 'This workspace',
                owner: false,
                since: null,
                clientId: null,
                clientName: null,
                projectId: null,
                projectName: null,
                reasonCode: 'TRACKING_'.strtoupper($issue->code),
                reasonParams: $issue->subject === null ? [] : ['subject' => $issue->subject],
            );
        }

        return $items;
    }

    private function severity(TrackingSeverity $severity): ?AttentionSeverity
    {
        return match ($severity) {
            TrackingSeverity::Critical => AttentionSeverity::Critical,
            TrackingSeverity::Warning => AttentionSeverity::NeedsAttention,
            TrackingSeverity::Info => null,
        };
    }
}
