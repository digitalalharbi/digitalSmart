<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTO;

use App\Domain\Attention\DigestKind;
use App\Domain\Notifications\DigestChannel;
use App\Domain\Notifications\NotificationCategory;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

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
        /** Local hour quiet time starts, in the person's zone or the workspace's. Null with `quietUntil`: no quiet hours. */
        public ?int $quietFrom = null,
        /** Local hour quiet time ends (exclusive). May be earlier than `quietFrom`: the window wraps midnight. */
        public ?int $quietUntil = null,
        /** Set by the snooze action, never by saving preferences. */
        public ?DateTimeImmutable $snoozedUntil = null,
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

    /**
     * Whether this person asked not to be interrupted at this instant: snoozed, or inside their quiet hours.
     *
     * Quiet is about interruption, not about being told. The notification is still written; what is withheld is the
     * push and the copy over another channel — and a critical one is never withheld, which the callers decide.
     */
    public function isQuietAt(DateTimeImmutable $now, string $workspaceZone): bool
    {
        if ($this->snoozedUntil !== null && $this->snoozedUntil > $now) {
            return true;
        }

        if ($this->quietFrom === null || $this->quietUntil === null) {
            return false;
        }

        try {
            $zone = new DateTimeZone($this->timezone ?? $workspaceZone);
        } catch (Throwable) {
            $zone = new DateTimeZone('UTC');
        }

        $hour = (int) $now->setTimezone($zone)->format('G');

        return $this->quietFrom < $this->quietUntil
            ? $hour >= $this->quietFrom && $hour < $this->quietUntil
            : $hour >= $this->quietFrom || $hour < $this->quietUntil;
    }

    public function mutes(NotificationCategory $category): bool
    {
        return in_array($category, $this->mutedCategories, true);
    }
}
