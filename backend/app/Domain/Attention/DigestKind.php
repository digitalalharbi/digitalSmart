<?php

declare(strict_types=1);

namespace App\Domain\Attention;

/**
 * Three digests, each answering one role's morning question.
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

    public function label(): string
    {
        return match ($this) {
            self::MorningCampaigns => 'Morning campaign digest',
            self::DailyBudget => 'Daily budget digest',
            self::WeeklyProjects => 'Weekly project digest',
        };
    }

    /** @return list<AttentionStream> */
    public function streams(): array
    {
        return match ($this) {
            self::MorningCampaigns => [AttentionStream::Campaigns, AttentionStream::Tracking],
            self::DailyBudget => [AttentionStream::Budgets],
            self::WeeklyProjects => [AttentionStream::Approvals, AttentionStream::Requests, AttentionStream::Tasks],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
