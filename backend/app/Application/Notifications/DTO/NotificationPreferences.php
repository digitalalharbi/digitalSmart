<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTO;

use App\Domain\Attention\DigestKind;
use App\Domain\Notifications\DigestChannel;
use App\Domain\Notifications\NotificationCategory;

/**
 * What one person asked for. Absent preferences are these defaults, so a new member is told everything.
 */
final readonly class NotificationPreferences
{
    /**
     * @param  list<DigestChannel>  $channels
     * @param  list<string>|null  $projectIds  null means every project they can see
     * @param  list<NotificationCategory>  $mutedCategories
     */
    public function __construct(
        public bool $daily = true,
        public bool $weekly = true,
        public bool $monthly = true,
        public array $channels = [DigestChannel::InApp],
        /** null means the workspace's own time zone. */
        public ?string $timezone = null,
        public int $hour = 9,
        public ?string $clientId = null,
        public ?array $projectIds = null,
        public array $mutedCategories = [],
    ) {}

    public static function defaults(): self
    {
        return new self;
    }

    public function wants(DigestKind $kind): bool
    {
        return match ($kind) {
            DigestKind::DailyPerformance => $this->daily,
            DigestKind::WeeklyPerformance => $this->weekly,
            DigestKind::MonthlyPerformance => $this->monthly,
            default => true,
        };
    }

    public function mutes(NotificationCategory $category): bool
    {
        return in_array($category, $this->mutedCategories, true);
    }
}
