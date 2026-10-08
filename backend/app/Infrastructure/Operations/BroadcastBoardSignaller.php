<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations;

use App\Application\Operations\Contract\BoardSignaller;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Operations\OperationsBoard;
use DateTimeImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Broadcasts the nudge, and never fails the action it describes.
 *
 * Caught and reported, exactly as the audit recorder and `Announce` are. A task that moved and whose signal threw
 * would leave the move recorded, the board un-nudged and the caller shown a failure for something that did
 * happen — and a realtime convenience is the last thing that should be able to do that.
 *
 * Nothing is dispatched when no actor is bound. That is not an error: a seeder or a console command legitimately
 * changes a board with nobody watching, and there is no workspace to address the channel to.
 */
final readonly class BroadcastBoardSignaller implements BoardSignaller
{
    public function __construct(
        private Dispatcher $events,
        private ActorContext $actor,
        private Clock $clock,
        private LoggerInterface $log,
    ) {}

    public function changed(OperationsBoard $board): void
    {
        try {
            $this->events->dispatch(new BoardChanged(
                $this->actor->workspaceId()->value,
                $board,
                $this->clock->now()->format(DateTimeImmutable::ATOM),
            ));
        } catch (Throwable $exception) {
            $this->log->warning('A board signal could not be broadcast.', [
                'board' => $board->value,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
