<?php

declare(strict_types=1);

namespace App\Application\Saas\DTO;

use App\Domain\Saas\SubscriptionStatus;
use DateTimeImmutable;

final readonly class SubscriptionState
{
    public function __construct(
        public SubscriptionStatus $status,
        public ?DateTimeImmutable $trialEndsAt,
        public ?DateTimeImmutable $graceEndsAt,
        public ?string $reason,
        public ?DateTimeImmutable $changedAt,
        public ?string $changedBy,
    ) {}
}
