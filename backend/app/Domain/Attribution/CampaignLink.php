<?php

declare(strict_types=1);

namespace App\Domain\Attribution;

/**
 * How an attributed conversion was tied to an **ad platform campaign**.
 *
 * A separate question from whether the conversion carried attribution at all, and the one that
 * decides whether spend and revenue can be put on the same row. An order carrying
 * `utm_campaign=autumn-range` names a campaign; whether this workspace's Meta account contains a
 * campaign called `autumn-range` is another matter, and the honest report counts both the spend with
 * no matching revenue and the revenue with no matching spend rather than quietly dropping either.
 */
enum CampaignLink: string
{
    /**
     * The order's campaign parameter equals an ad campaign's name.
     *
     * Compared case-insensitively with surrounding space removed, and **nothing looser**. No prefix
     * matching, no fuzzy similarity: `autumn-range` and `autumn-range-v2` are two campaigns, and a
     * report that merged them would credit a budget decision to the wrong one.
     */
    case ExactName = 'exact_name';

    /**
     * The order carried a platform click identifier and no campaign name this product can resolve.
     *
     * `gclid` and `fbclid` identify a click, and turning one into a campaign requires the platform's
     * own click-level API, which this product does not read. So the conversion is genuinely
     * attributed — the click reached the checkout — and genuinely cannot be put on a campaign row.
     */
    case ClickNotResolved = 'click_not_resolved';

    /** The order named a campaign and no ad campaign of that name exists in this workspace. */
    case NoMatchingCampaign = 'no_matching_campaign';

    /** The order named a channel but no campaign. Never split across the channel's campaigns. */
    case ChannelOnly = 'channel_only';

    /** Whether spend and revenue may appear on the same row for this link. */
    public function joinsSpend(): bool
    {
        return $this === self::ExactName;
    }

    public function label(): string
    {
        return match ($this) {
            self::ExactName => 'Matched by campaign name',
            self::ClickNotResolved => 'Click identifier, campaign not resolvable',
            self::NoMatchingCampaign => 'No campaign of that name',
            self::ChannelOnly => 'Channel named, campaign not',
        };
    }

    /** Why this row carries no spend, for the reader looking at an empty cell. */
    public function explanation(): ?string
    {
        return match ($this) {
            self::ExactName => null,
            self::ClickNotResolved => 'The order carried a platform click identifier. Resolving one to '
                .'a campaign needs the platform\'s click-level API, which this product does not read.',
            self::NoMatchingCampaign => 'No campaign of this name exists in the connected ad accounts. '
                .'The parameter may be from a campaign that has been renamed or deleted, or from one '
                .'running on an account nobody has connected.',
            self::ChannelOnly => 'The order named a source or medium but no campaign. Dividing this '
                .'revenue among the channel\'s campaigns would be inventing the split.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
