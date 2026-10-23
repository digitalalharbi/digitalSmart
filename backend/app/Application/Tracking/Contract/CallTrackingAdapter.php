<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Domain\Tracking\CallEvidence;
use DateTimeImmutable;

/**
 * Where a call-tracking provider would plug in. Nothing implements it today: `BLOCKED_EXTERNAL`.
 *
 * Reading calls needs a provider account and its consent to be read. Until one exists the product records no call, scores
 * no call and shows none, and says "no call evidence source" rather than a zero.
 */
interface CallTrackingAdapter
{
    /**
     * Calls the provider reports for one tracking number between two moments.
     *
     * @return list<CallEvidence>
     */
    public function calls(string $trackingNumberId, DateTimeImmutable $from, DateTimeImmutable $until): array;
}
