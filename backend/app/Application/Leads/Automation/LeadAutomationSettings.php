<?php

declare(strict_types=1);

namespace App\Application\Leads\Automation;

use App\Domain\Leads\Automation\AssignmentMode;

/** What a project does with a new lead, and the response time it promises. */
final readonly class LeadAutomationSettings
{
    public function __construct(
        public string $projectId,
        public bool $enabled,
        public AssignmentMode $mode,
        public ?string $ownerId,
        public bool $createTask,
        public ?string $taskTitleAr,
        public ?string $taskTitleEn,
        public string $taskPriority,
        public bool $notifyOwner,
        public ?int $slaMinutes,
    ) {}

    public static function off(string $projectId): self
    {
        return new self($projectId, false, AssignmentMode::None, null, false, null, null, 'high', true, null);
    }
}
