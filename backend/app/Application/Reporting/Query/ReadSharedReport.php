<?php

declare(strict_types=1);

namespace App\Application\Reporting\Query;

use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\Contract\ReportShareStore;
use App\Application\Reporting\Contract\SnapshotSerialiser;
use App\Application\Shared\Contract\Clock;
use App\Domain\Reporting\RenderMode;
use App\Domain\Reporting\ReportAudience;
use App\Domain\Reporting\ShareState;

/**
 * Read a report through a shared link, as a visitor with no account.
 *
 * The one unauthenticated read in this product, which is why everything about it is narrow.
 *
 * **No actor, no permissions, no visibility resolver.** The token *is* the authorisation, and it authorises exactly
 * one report. There is no parameter a visitor can change to see a different one.
 *
 * **Client-safe, always.** The audience is passed explicitly rather than taken from the report, so no future
 * configuration can make this path serve an internal render. `ShareReport` already refuses to share an internal
 * report at all; this is the second lock on the same door.
 *
 * **A refusal is a sentence, not a 404.** A link a client was sent returning "not found" reads as a broken site,
 * and they ask for it again instead of learning that it lapsed.
 */
final readonly class ReadSharedReport
{
    public function __construct(
        private ReportShareStore $shares,
        private ReportRepository $reports,
        private RenderReport $render,
        private SnapshotSerialiser $serialiser,
        private Clock $clock,
    ) {}

    /**
     * @return array{
     *     state: ShareState,
     *     message: ?string,
     *     mode: ?RenderMode,
     *     payload: ?array<string, mixed>,
     *     recipient: ?string,
     * }
     */
    public function __invoke(string $token): array
    {
        $share = $this->shares->findByToken($token);

        if ($share === null) {
            /*
             * A token nobody issued. Reported as revoked rather than as unknown: telling a visitor the difference
             * between "never existed" and "turned off" tells anybody guessing tokens when they have guessed one.
             */
            return [
                'state' => ShareState::Revoked,
                'message' => ShareState::Revoked->visitorMessage(),
                'mode' => null,
                'payload' => null,
                'recipient' => null,
            ];
        }

        $now = $this->clock->now();
        $state = $share->state($now);

        if (! $state->allowsAccess()) {
            return [
                'state' => $state,
                'message' => $state->visitorMessage(),
                'mode' => null,
                'payload' => null,
                'recipient' => null,
            ];
        }

        if ($share->snapshotId !== null) {
            $snapshot = $this->reports->findSnapshot($share->workspaceId, $share->snapshotId);

            if ($snapshot === null) {
                return [
                    'state' => ShareState::Revoked,
                    'message' => ShareState::Revoked->visitorMessage(),
                    'mode' => null,
                    'payload' => null,
                    'recipient' => null,
                ];
            }

            $this->shares->recordView($share->id, $now);

            /*
             * The stored payload, unchanged. Not re-rendered, not merged with today's figures — that is the whole
             * reason a snapshot exists, and the moment it was taken is inside the payload.
             */
            return [
                'state' => $state,
                'message' => null,
                'mode' => RenderMode::Snapshot,
                'payload' => $snapshot['payload'],
                'recipient' => $share->recipientLabel,
            ];
        }

        /*
         * A live link. `null` for visible projects because the token is the authorisation and it names one report;
         * there is no actor whose project assignments could narrow it further.
         */
        $report = $this->reports->find($share->workspaceId, null, $share->reportId);

        if ($report === null) {
            return [
                'state' => ShareState::Revoked,
                'message' => ShareState::Revoked->visitorMessage(),
                'mode' => null,
                'payload' => null,
                'recipient' => null,
            ];
        }

        $this->shares->recordView($share->id, $now);

        $rendered = ($this->render)(
            $share->workspaceId,
            $report,
            // Explicit, so no future configuration can make this path serve an internal render.
            ReportAudience::ClientSafe,
        );

        return [
            'state' => $state,
            'message' => null,
            'mode' => RenderMode::Live,
            'payload' => $this->serialiser->toArray($rendered),
            'recipient' => $share->recipientLabel,
        ];
    }
}
