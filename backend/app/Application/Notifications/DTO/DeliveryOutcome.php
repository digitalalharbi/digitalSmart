<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTO;

use App\Domain\Notifications\DeliveryStatus;

/** One channel's answer, with the sentence an operator reads to know what is missing. */
final readonly class DeliveryOutcome
{
    public function __construct(
        public DeliveryStatus $status,
        public ?string $detail = null,
    ) {}
}
