<?php

declare(strict_types=1);

namespace App\Application\Shared\Contract;

use App\Application\Shared\DTO\WorkBacklog;

/**
 * What the queue actually holds, beyond whether it answers.
 *
 * A depth is not a health signal. Five jobs on a queue nothing is consuming and five thousand on a queue keeping
 * up look the same from the count alone, and the first is the one that matters: a stalled worker means a sync
 * that never ran, a webhook never processed, and a figure on a screen that somebody believes is current.
 *
 * The signal is **age**, so this contract asks for the oldest waiting job's timestamp as well as the depth — and
 * is allowed to answer that it cannot read it. Not every queue driver publishes it, and a probe that reported
 * "nothing is stalled" because it could not look would be worse than one that says so.
 */
interface WorkLedger
{
    public function backlog(): WorkBacklog;

    /** Jobs that gave up. Each one is work somebody may believe happened. */
    public function failures(): WorkBacklog;
}
