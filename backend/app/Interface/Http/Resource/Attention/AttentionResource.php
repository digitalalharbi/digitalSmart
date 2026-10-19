<?php

declare(strict_types=1);

namespace App\Interface\Http\Resource\Attention;

use App\Application\Attention\DTO\AttentionItem;
use App\Application\Attention\DTO\AttentionQueue;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;
use DateTimeInterface;
use stdClass;

final readonly class AttentionResource
{
    /** @return array<string, mixed> */
    public static function item(AttentionItem $item): array
    {
        return [
            'id' => $item->id,
            'stream' => $item->stream->value,
            'stream_label' => $item->stream->label(),
            'severity' => $item->severity->value,
            'severity_label' => $item->severity->label(),
            'message' => $item->message,
            /* The interface writes the sentence from these, in the reader's language; `message` is the English fallback. */
            'reason' => $item->reasonCode === null ? null : ['code' => $item->reasonCode, 'params' => $item->reasonParams === [] ? new stdClass : $item->reasonParams],
            'entity' => [
                'kind' => $item->entityKind,
                'label' => $item->entityLabel,
            ],
            /*
             * Three states kept distinct on the wire. A name; `null` for "nobody holds this", which the
             * interface prints as *Unassigned*; and the key being absent for a kind that has no owner at
             * all, so a budget is not given a gap it never had.
             */
            ...($item->owner === false ? [] : ['owner' => $item->owner]),
            'since' => $item->since?->format(DateTimeInterface::ATOM),
            'client_id' => $item->clientId,
            'client_name' => $item->clientName,
            'project_id' => $item->projectId,
            'project_name' => $item->projectName,
            'action' => [
                'path' => $item->stream->path(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function queue(AttentionQueue $queue): array
    {
        return [
            'data' => array_map(
                static fn (AttentionItem $item): array => self::item($item),
                $queue->items,
            ),
            'meta' => [
                'severity_counts' => $queue->severityCounts,
                'total' => $queue->total,
                /*
                 * What was actually looked at. An empty list means something different to a finance
                 * manager and a creative lead, and without this it would read as a claim about the whole
                 * product rather than about the part this reader can see.
                 */
                'covered' => array_map(
                    static fn (AttentionStream $stream): array => [
                        'stream' => $stream->value,
                        'label' => $stream->label(),
                    ],
                    $queue->covered,
                ),
                /* Named, so a broken query and a quiet week do not render identically. */
                'failed' => array_map(
                    static fn (AttentionStream $stream): string => $stream->value,
                    $queue->failed,
                ),
                'as_of' => $queue->asOf->format(DateTimeInterface::ATOM),
                /* Said, so a narrowed list is never read as the whole agency's. */
                'audience' => $queue->audience->value,
            ] + self::vocabulary(),
        ];
    }

    /** @return array<string, mixed> */
    public static function vocabulary(): array
    {
        return [
            'severities' => array_map(static fn (AttentionSeverity $severity): array => [
                'severity' => $severity->value,
                'label' => $severity->label(),
                'rank' => $severity->rank(),
            ], AttentionSeverity::cases()),
            'streams' => array_map(static fn (AttentionStream $stream): array => [
                'stream' => $stream->value,
                'label' => $stream->label(),
                'path' => $stream->path(),
            ], AttentionStream::cases()),
        ];
    }
}
