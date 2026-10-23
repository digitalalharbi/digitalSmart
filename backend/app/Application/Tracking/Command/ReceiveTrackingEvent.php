<?php

declare(strict_types=1);

namespace App\Application\Tracking\Command;

use App\Application\Shared\Contract\Clock;
use App\Application\Tracking\Contract\TrackingEventStore;
use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\TrackingEventName;

/**
 * A destination page reports that something happened.
 *
 * Anybody can send this, so it keeps only what the touchpoint it names already vouches for: the project, the link and
 * the visitor come from this product's own record of the click, never from the request. An unknown touchpoint is
 * dropped silently — answering "no such touchpoint" would let a caller probe for valid ones.
 */
final readonly class ReceiveTrackingEvent
{
    /** The only metadata keys kept. No field *values* are ever among them. */
    private const array METADATA_KEYS = ['step', 'field_key', 'error_code', 'form_token'];

    public function __construct(private TrackingEventStore $events, private Clock $clock) {}

    /** @param array<string, mixed> $metadata */
    public function __invoke(
        string $touchpointId,
        TrackingEventName $name,
        string $eventId,
        ?ConsentState $consent,
        ?string $path,
        array $metadata,
    ): void {
        if (! $name->isBrowserReportable()) {
            return;
        }

        $touchpoint = $this->events->touchpoint($touchpointId);

        if ($touchpoint === null) {
            return;
        }

        $kept = [];

        foreach (self::METADATA_KEYS as $key) {
            $value = $metadata[$key] ?? null;

            if (is_scalar($value)) {
                $kept[$key] = mb_substr((string) $value, 0, 80);
            }
        }

        $this->events->recordBrowserEvent(
            $touchpoint,
            $name,
            $eventId,
            $consent ?? $touchpoint->consent,
            $path !== null ? mb_substr(strtok($path, '?#') ?: '', 0, 512) : null,
            $kept,
            $this->clock->now(),
        );
    }
}
