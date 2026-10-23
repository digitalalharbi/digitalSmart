<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Cost;

/**
 * The spend a tracking link may be credited with, over one period, and the honest label for it.
 *
 * `level` is the entity level the spend was read at (campaign, ad_group or ad). A cost metric is only ever computed from
 * a spend and a denominator of the same link, so mixing levels cannot happen here: a campaign-level spend is never
 * divided by an ad-level outcome, because a link has one binding and the spend is that binding's.
 */
final readonly class LinkSpend
{
    /**
     * @param  array{method: string, share: float, coverage: ?float, confidence: string}|null  $allocation
     */
    private function __construct(
        public SpendBasis $basis,
        public ?int $minor,
        public ?string $currency,
        public ?string $level,
        public ?string $reason,
        public ?int $daysReported,
        public ?array $allocation,
        /** For an unavailable spend that exists but is shared: how much the entity spent, which is not this link's. */
        public ?int $sharedMinor = null,
    ) {}

    public static function direct(int $minor, string $currency, string $level, int $daysReported): self
    {
        return new self(SpendBasis::Direct, $minor, $currency, $level, null, $daysReported, null);
    }

    /** @param array{method: string, share: float, coverage: ?float, confidence: string} $allocation */
    public static function allocated(int $minor, string $currency, string $level, int $daysReported, array $allocation): self
    {
        return new self(SpendBasis::Allocated, $minor, $currency, $level, null, $daysReported, $allocation);
    }

    public static function unavailable(string $reason, ?string $level = null): self
    {
        return new self(SpendBasis::Unavailable, null, null, $level, $reason, null, null);
    }

    /** Spend exists for the entity but cannot be credited to this link; the amount is kept so it can be reported as unattributed. */
    public static function shared(string $reason, string $level, int $minor): self
    {
        return new self(SpendBasis::Unavailable, null, null, $level, $reason, null, null, $minor);
    }
}
