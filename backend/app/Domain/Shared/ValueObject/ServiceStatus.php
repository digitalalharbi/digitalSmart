<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObject;

/**
 * Health of a dependency the platform needs.
 *
 * Deliberately three-valued: "degraded" lets the platform stay usable while a
 * non-critical dependency (e.g. a queue worker) is unavailable, instead of
 * pretending everything is fine or failing the whole system.
 */
enum ServiceStatus: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';

    /** The worst status in a set, which becomes the overall system status. */
    public static function worst(self ...$statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Degraded => 1,
            self::Down => 2,
        };
    }

    public function isOperational(): bool
    {
        return $this !== self::Down;
    }
}
