<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Application\Commerce\DTO\OrderRecord;

/**
 * Turns one platform's order payload into a normalised order.
 *
 * The same mapping the sync adapter uses, exposed as a port because a webhook carries the same entity
 * by a different route. **One mapper for both** is the point: a second one for webhooks is how the two
 * paths come to disagree about what a status means, and the disagreement would show up as a client's
 * revenue changing when a nightly sync overwrote what a webhook had written an hour earlier.
 */
interface StoreOrderMapper
{
    /** @param array<string, mixed> $payload */
    public function order(array $payload): OrderRecord;
}
