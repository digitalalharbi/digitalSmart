<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

/**
 * How a campaign is doing, in the product's own words.
 *
 * **Not the provider's status.** Meta's `effective_status` says whether the platform is delivering the
 * campaign; this says whether a person needs to do something about it. Both are published and neither
 * replaces the other: a campaign can be ACTIVE on the platform and critical here, which is precisely the
 * case somebody needs to find.
 *
 * The same six words every other entity in the product uses — client, project, budget, tracking source,
 * integration, creative — because a reader holding three of them should not have to learn three
 * vocabularies to ask one question.
 */
enum CampaignHealth: string
{
    case Healthy = 'healthy';
    case Watch = 'watch';
    case NeedsAttention = 'needs_attention';
    case Critical = 'critical';
    /** Paused, finished or archived. Not a problem — an absence of one. */
    case Inactive = 'inactive';
    /** Too few days of reported figures to say anything. A real answer, and never folded into healthy. */
    case InsufficientData = 'insufficient_data';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Watch => 'Watch',
            self::NeedsAttention => 'Needs attention',
            self::Critical => 'Critical',
            self::Inactive => 'Inactive',
            self::InsufficientData => 'Not enough data',
        };
    }

    public function needsAttention(): bool
    {
        return $this === self::NeedsAttention || $this === self::Critical;
    }

    /** Worst first. Inactive and insufficient sink below healthy: neither is work. */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::NeedsAttention => 1,
            self::Watch => 2,
            self::InsufficientData => 3,
            self::Healthy => 4,
            self::Inactive => 5,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
