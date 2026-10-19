<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * How far a provider operation has been proven, in order. Nothing skips a rung: an operation is not live-verified
 * because its sandbox run passed, and a live write is never claimed from a live read.
 *
 * - `code_verified`: the operation is modelled, gated and tested against the documented contract.
 * - `sandbox_verified`: it has run end to end against sandbox credentials and fixture-shaped responses.
 * - `awaiting_credentials`: the next rung needs a real connection this installation does not hold.
 * - `live_read_verified`: real credentials, a real read, and the result seen in the product.
 * - `live_write_verified`: a real write, confirmed by reading the platform back — the only proof of a write.
 */
enum ReadinessState: string
{
    case CodeVerified = 'code_verified';
    case SandboxVerified = 'sandbox_verified';
    case AwaitingCredentials = 'awaiting_credentials';
    case LiveReadVerified = 'live_read_verified';
    case LiveWriteVerified = 'live_write_verified';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
