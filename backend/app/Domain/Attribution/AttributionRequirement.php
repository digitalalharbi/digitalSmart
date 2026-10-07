<?php

declare(strict_types=1);

namespace App\Domain\Attribution;

/**
 * What a model needs in order to be computed at all.
 *
 * Declared as data rather than left implicit, because the answer to "why can't we show
 * time-decay attribution?" is a property of the data this product holds, not of how much work
 * somebody is willing to do. Publishing it means the interface can offer the model, say it is
 * unavailable, and say what would make it available — instead of the model quietly not existing and
 * somebody asking for it again every quarter.
 */
enum AttributionRequirement: string
{
    /**
     * Only what the conversion itself carried.
     *
     * The order arrived with a UTM set or a click identifier on it. Nothing is inferred and nothing
     * outside that one record is read.
     */
    case ConversionRecord = 'conversion_record';

    /**
     * The person's other conversions, resolved on contact details.
     *
     * Available because Phase 16 built it: enquiries and orders linked on normalised phone numbers
     * and e-mail addresses. It is a history of **conversions**, not of marketing touches.
     */
    case PersonHistory = 'person_history';

    /**
     * The marketing touches before a conversion — impressions, clicks, sessions, in order.
     *
     * **This product does not have them.** Google Analytics' Data API returns aggregated reports
     * rather than per-user event streams, and there is no user-level identifier available through it;
     * the documented route to event-level data is a BigQuery export this product does not read. So
     * the touches are absent rather than nonexistent, and a model that needs them cannot be computed
     * — only faked.
     */
    case TouchPath = 'touch_path';

    public function isAvailable(): bool
    {
        return $this !== self::TouchPath;
    }

    /** What is missing, in a sentence a reader can act on. */
    public function missing(): ?string
    {
        return match ($this) {
            self::TouchPath => 'The marketing touches before a conversion are not available to this '
                .'product. Analytics reports arrive aggregated, with no user-level identifier, so '
                .'there is no path to spread credit along.',
            default => null,
        };
    }

    /** What would make it available. Concrete, because somebody has to decide whether to pay for it. */
    public function wouldUnblock(): ?string
    {
        return match ($this) {
            self::TouchPath => 'Either a Google Analytics to BigQuery export, which gives event-level '
                .'rows with a pseudonymous user id and needs a Cloud project with billing, or '
                .'first-party tracking served by this product, which is a larger piece of work and '
                .'brings consent obligations.',
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ConversionRecord => 'What the conversion carried',
            self::PersonHistory => "The person's other conversions",
            self::TouchPath => 'The marketing touches before the conversion',
        };
    }
}
