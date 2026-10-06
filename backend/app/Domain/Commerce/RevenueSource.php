<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

/**
 * Who says this revenue figure is true.
 *
 * The product's central honesty rule in enum form: **these are never silently merged.** A client's
 * Google Ads conversions, their GA4 purchases, their Salla orders and their CRM sales will all
 * disagree, by design, because each counts something different —
 *
 *  - an ad platform reports what it believes it *caused*, a claim about its own effectiveness;
 *  - an analytics property reports what a *browser* said a sale was worth, measured before the
 *    payment failed or the item came back;
 *  - a store reports what was *invoiced*;
 *  - a CRM reports what a person *recorded*.
 *
 * Adding two of those together produces a number no one can defend and everyone will quote. So a
 * revenue figure in this product always names its source, a reader always chooses which one they
 * are looking at, and the only arithmetic across sources is a deliberate, labelled comparison.
 */
enum RevenueSource: string
{
    /** What the ad platform claims it caused. The weakest claim, and the loudest. */
    case PlatformReported = 'platform_reported';

    /** What a browser reported to an analytics property. */
    case GoogleAnalytics = 'google_analytics';

    case Salla = 'salla';

    case Zid = 'zid';

    case Crm = 'crm';

    /** Somebody typed it. Honest, and not to be presented as measured. */
    case Manual = 'manual';

    /** Worked out by an attribution model in this product. Never presented as actual. */
    case Attributed = 'attributed';

    /**
     * Whether this source invoiced the money it reports.
     *
     * The only sources that can answer "what did this client actually earn". Everything else is a
     * measurement of, or a claim about, that number.
     */
    public function isInvoiced(): bool
    {
        return $this === self::Salla || $this === self::Zid || $this === self::Crm;
    }

    /**
     * Whether a figure from this source is a model's output rather than a record.
     *
     * Attributed revenue must never be shown as actual, and a reader must be able to see which it
     * is without reading the documentation.
     */
    public function isModelled(): bool
    {
        return $this === self::Attributed;
    }

    public function label(): string
    {
        return match ($this) {
            self::PlatformReported => 'Platform reported',
            self::GoogleAnalytics => 'Google Analytics',
            self::Salla => 'Salla',
            self::Zid => 'Zid',
            self::Crm => 'CRM',
            self::Manual => 'Entered by hand',
            self::Attributed => 'Attributed',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
