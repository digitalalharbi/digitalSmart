<?php

declare(strict_types=1);

namespace App\Application\Leads\Automation;

use DateTimeImmutable;

/** One lead as the follow-up queue and the response-time alerts read it. */
final readonly class FollowUpRow
{
    public function __construct(
        public string $workspaceId,
        public string $projectId,
        public string $clientId,
        public string $leadId,
        public ?string $name,
        public ?string $sourceDetail,
        public string $stage,
        public ?string $ownerId,
        public ?string $ownerName,
        public ?string $assignmentReason,
        public DateTimeImmutable $receivedAt,
        public ?DateTimeImmutable $firstAttemptAt,
        public ?DateTimeImmutable $firstContactedAt,
        public ?int $slaMinutes,
    ) {}
}
