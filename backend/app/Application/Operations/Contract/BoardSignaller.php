<?php

declare(strict_types=1);

namespace App\Application\Operations\Contract;

use App\Domain\Operations\OperationsBoard;

/**
 * Tell whoever has a board open that it changed.
 *
 * A port, so a use case never imports a broadcaster — and so the whole thing is a no-op in a test without
 * anybody mocking a WebSocket server.
 *
 * **It must never fail the action it describes.** A task that moved and whose signal threw would leave the move
 * recorded, the board un-nudged and the caller shown a failure for something that did happen. The implementation
 * catches and reports, exactly as the audit recorder and `Announce` do.
 */
interface BoardSignaller
{
    public function changed(OperationsBoard $board): void;
}
