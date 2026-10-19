<?php

declare(strict_types=1);

namespace App\Application\Automation\DTO;

use App\Domain\Automation\RuleAction;
use App\Domain\Automation\RuleMetric;
use App\Domain\Automation\RuleMode;
use App\Domain\Automation\RuleOperator;
use App\Domain\Automation\RuleSchedule;
use App\Domain\Automation\RuleSeverity;
use App\Domain\Automation\TrustLevel;
use DateTimeImmutable;

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
        /**
         * Further conditions that must all hold as well (AND). Each is a metric and, where it takes one, a limit.
         *
         * @var list<array{metric: RuleMetric, threshold: float|null, operator?: RuleOperator}>
         */
        public array $conditions = [],
        /** How many days in a row it must hold before the action runs. One means the first time it is seen. */
        public int $forDays = 1,
        public RuleAction $action = RuleAction::Alert,
        /** For a budget-cut proposal: by how much, in percent. */
        public ?int $actionPct = null,
        public TrustLevel $trustLevel = TrustLevel::Propose,
        /** How it goes when it holds: monitor, recommend (default) or auto (refused until authorised). */
        public RuleMode $mode = RuleMode::Recommend,
        /** `all` (AND) or `any` (OR) across the first condition and the further ones. */
        public string $logic = 'all',
        /** @var list<string> Platforms in scope; empty is every platform. */
        public array $providers = [],
        /** @var list<string> Ad accounts (external account ids) in scope; empty is every account. */
        public array $accountIds = [],
        /** @var list<string> Campaigns in scope; empty is every campaign the rest of the scope allows. */
        public array $campaignIds = [],
        public string $objectLevel = 'campaign',
        public RuleSchedule $schedule = RuleSchedule::Hourly,
        /** HH:MM in `timezone`, for a daily rule. */
        public ?string $scheduleTime = null,
        public string $timezone = 'Asia/Riyadh',
        /** After acting on an object, the rule leaves it alone this long. */
        public int $cooldownHours = 24,
        /** Data sufficiency: a campaign below any of these is not judged at all — "insufficient data", no action. */
        public ?float $minSpend = null,
        public ?int $minAgeDays = null,
        public ?float $minConversions = null,
        public ?DateTimeImmutable $lastRunAt = null,
        /** @var array<string, mixed>|null */
        public ?array $lastResult = null,
        /** @var list<string> Ad groups in scope; empty is every one the rest of the scope allows. */
        public array $adGroupIds = [],
        /** @var list<string> Ads in scope; empty is every one the rest of the scope allows. */
        public array $adIds = [],
    ) {}

    /** The level the rule judges and acts on. */
    public function level(): \App\Domain\Advertising\AdEntityType
    {
        return \App\Domain\Advertising\AdEntityType::tryFrom($this->objectLevel) ?? \App\Domain\Advertising\AdEntityType::Campaign;
    }
}
