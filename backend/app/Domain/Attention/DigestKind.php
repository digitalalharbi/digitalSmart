<?php

declare(strict_types=1);

namespace App\Domain\Attention;

/**
 * Four digests, each answering one role's question at its own rhythm.
 *
 * Which streams feed each is the whole definition. A media buyer's morning digest is about campaigns and
 * tracking; finance's is about budgets; a project manager's weekly one is about the work waiting between
 * people. Role-awareness here is not a role list — it is the permission each stream already carries, so a
 * person is only ever given a digest about things they could open.
 */
enum DigestKind: string
{
    case MorningCampaigns = 'morning_campaigns';
    case DailyBudget = 'daily_budget';
    case WeeklyProjects = 'weekly_projects';
    case MonthlyExecutive = 'monthly_executive';

    /*
     * The performance digests. Not attention lists: figures against the earlier period, best and weakest
     * platform, the campaign to look at, the creative wearing out, and a handful of action cards — composed by
     * `BuildPerformanceDigests` from the analytics layer, in the reader's own scope.
     */
    case DailyPerformance = 'daily_performance';
    case WeeklyPerformance = 'weekly_performance';
    case MonthlyPerformance = 'monthly_performance';

    public function label(): string
    {
        return match ($this) {
            self::MorningCampaigns => 'Morning campaign digest',
            self::DailyBudget => 'Daily budget digest',
            self::WeeklyProjects => 'Weekly project digest',
            self::MonthlyExecutive => 'Monthly executive digest',
            self::DailyPerformance => 'Daily performance digest',
            self::WeeklyPerformance => 'Weekly performance digest',
            self::MonthlyPerformance => 'Monthly executive summary',
        };
    }

    /** @return list<AttentionStream> */
    public function streams(): array
    {
        return match ($this) {
            self::MorningCampaigns => [AttentionStream::Campaigns, AttentionStream::Tracking],
            self::DailyBudget => [AttentionStream::Budgets],
            self::WeeklyProjects => [AttentionStream::Approvals, AttentionStream::Requests, AttentionStream::Tasks],
            /* Everything, and worst first: the executive reads the three largest risks, not the queue. */
            self::MonthlyExecutive => AttentionStream::cases(),
            /* The performance digests read the queue for their action cards: what a figure cannot say. */
            self::DailyPerformance, self::WeeklyPerformance => [AttentionStream::Campaigns, AttentionStream::Budgets, AttentionStream::Tracking, AttentionStream::Automation],
            self::MonthlyPerformance => AttentionStream::cases(),
        };
    }

    public function isPerformance(): bool
    {
        return $this === self::DailyPerformance || $this === self::WeeklyPerformance || $this === self::MonthlyPerformance;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
