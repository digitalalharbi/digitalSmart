<?php

declare(strict_types=1);

namespace App\Application\Automation\DTO;

use App\Domain\Automation\RuleAction;
use App\Domain\Automation\RuleMetric;
use App\Domain\Automation\RuleSeverity;
use App\Domain\Automation\TrustLevel;

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
        /**
         * Further conditions that must all hold as well (AND). Each is a metric and, where it takes one, a limit.
         *
         * @var list<array{metric: RuleMetric, threshold: float|null}>
         */
        public array $conditions = [],
        /** How many days in a row it must hold before the action runs. One means the first time it is seen. */
        public int $forDays = 1,
        public RuleAction $action = RuleAction::Alert,
        /** For a budget-cut proposal: by how much, in percent. */
        public ?int $actionPct = null,
        public TrustLevel $trustLevel = TrustLevel::Propose,
    ) {}
}
