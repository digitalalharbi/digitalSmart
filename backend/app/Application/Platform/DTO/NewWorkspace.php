<?php

declare(strict_types=1);

namespace App\Application\Platform\DTO;

use App\Domain\Saas\SubscriptionStatus;
use App\Domain\Workspace\WorkspaceType;
use DateTimeImmutable;

final readonly class NewWorkspace
{
    public function __construct(
        public string $name,
        public string $slug,
        public WorkspaceType $type,
        public string $locale,
        public string $timezone,
        public string $currency,
        public string $planCode,
        public SubscriptionStatus $status,
        public ?DateTimeImmutable $trialEndsAt,
        public string $ownerName,
        public string $ownerEmail,
    ) {}
}
