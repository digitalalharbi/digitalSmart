<?php

declare(strict_types=1);

namespace App\Application\Automation\DTO;

use App\Domain\Automation\RuleMetric;
use App\Domain\Automation\RuleSeverity;

final readonly class RuleInput
{
    public function __construct(
        public string $name,
        public RuleMetric $metric,
        public ?float $threshold,
        public int $windowDays,
        public RuleSeverity $severity,
        public bool $isActive,
        public ?string $clientId,
        public ?string $projectId,
    ) {}
}
