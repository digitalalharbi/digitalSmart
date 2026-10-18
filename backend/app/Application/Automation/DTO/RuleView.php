<?php

declare(strict_types=1);

namespace App\Application\Automation\DTO;

use App\Domain\Automation\RuleMetric;
use App\Domain\Automation\RuleOperator;
use App\Domain\Automation\RuleSeverity;

final readonly class RuleView
{
    public function __construct(
        public string $id,
        public string $name,
        public RuleMetric $metric,
        public RuleOperator $operator,
        public ?float $threshold,
        public int $windowDays,
        public RuleSeverity $severity,
        public bool $isActive,
        public ?string $clientId,
        public ?string $clientName,
        public ?string $projectId,
        public ?string $projectName,
        public ?string $authorName,
    ) {}
}
