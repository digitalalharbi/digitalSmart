<?php

declare(strict_types=1);

namespace App\Domain\Attribution;

/**
 * How credit for a conversion is assigned to marketing.
 *
 * **Every member of this enum except one is a model, and a model is an opinion.** First-touch,
 * last-touch and linear disagree with each other by design, and whichever one a report uses, the
 * figure it produces is a claim rather than a measurement. So each model carries `isModelled()`, and
 * everything built on it is obliged to say so on screen — the single rule this phase exists to
 * enforce.
 *
 * The exception is `LastRecorded`, which is not a model at all: it reports the campaign the
 * conversion itself arrived carrying. That is a fact about the order, and it is the only attribution
 * figure in this product that can be defended without qualification.
 *
 * Three models are declared and **cannot be computed**, and declaring them is deliberate. They need
 * the sequence of marketing touches before a conversion, which this product does not hold; leaving
 * them out of the enum would mean the interface could not explain their absence, and the question
 * would be asked again every quarter. `isAvailable()` is false for them, the requirement says what
 * is missing, and the use case refuses rather than approximating.
 */
enum AttributionModel: string
{
    /**
     * The campaign the conversion itself carried.
     *
     * Not a model: a record. The storefront wrote these parameters onto the order when the customer
     * checked out, which in practice makes it a last-click figure — but it is last-click *as
     * recorded*, with no assumption about what happened before.
     */
    case LastRecorded = 'last_recorded';

    /**
     * The campaign on this person's earliest recorded conversion.
     *
     * A model, and worth being exact about what it is a model of: the earliest **conversion** this
     * product has for that person, not their first ad impression. For a customer who enquired
     * through one campaign and later bought through another, it credits the enquiry's campaign —
     * which is a defensible view of what started the relationship and is not the same claim as
     * "first touch" in the usual sense.
     */
    case FirstRecorded = 'first_recorded';

    /**
     * The campaign the conversion carried, counted only where the link is a paid one.
     *
     * A click identifier, or a medium a platform uses for paid traffic. Useful because the question
     * an agency is actually asked is "what did the money we spent produce", and an organic or
     * direct conversion carrying a stray UTM set answers a different question.
     */
    case PaidLastClick = 'paid_last_click';

    /** Equal credit to every touch in the path. Needs the path. */
    case Linear = 'linear';

    /** Most credit to the first and last touch, the rest shared. Needs the path. */
    case PositionBased = 'position_based';

    /** Credit decaying with the time between touch and conversion. Needs the path. */
    case TimeDecay = 'time_decay';

    public function requires(): AttributionRequirement
    {
        return match ($this) {
            self::LastRecorded, self::PaidLastClick => AttributionRequirement::ConversionRecord,
            self::FirstRecorded => AttributionRequirement::PersonHistory,
            self::Linear, self::PositionBased, self::TimeDecay => AttributionRequirement::TouchPath,
        };
    }

    public function isAvailable(): bool
    {
        return $this->requires()->isAvailable();
    }

    /**
     * Whether a figure produced by this model is a claim rather than a record.
     *
     * Drives the label on every surface that shows it. `LastRecorded` is the only false, and it is
     * false because nothing is assumed: the order said which campaign it came from.
     */
    public function isModelled(): bool
    {
        return $this !== self::LastRecorded;
    }

    /**
     * Whether this model reads conversions other than the one being attributed.
     *
     * The models that do cannot be computed for an anonymous conversion, because there is no person
     * to gather a history for — which is a coverage limit rather than a failure, and is reported as
     * one.
     */
    public function needsPerson(): bool
    {
        return $this->requires() === AttributionRequirement::PersonHistory;
    }

    public function label(): string
    {
        return match ($this) {
            self::LastRecorded => 'As recorded on the order',
            self::FirstRecorded => 'First recorded conversion',
            self::PaidLastClick => 'Paid click, as recorded',
            self::Linear => 'Linear',
            self::PositionBased => 'Position based',
            self::TimeDecay => 'Time decay',
        };
    }

    /** One sentence saying what the model claims, for the selector that offers it. */
    public function description(): string
    {
        return match ($this) {
            self::LastRecorded => 'Credits the campaign written onto the order at checkout. The only '
                .'attribution figure here that is a record rather than a claim.',
            self::FirstRecorded => 'Credits the campaign on the earliest conversion recorded for the '
                .'same person. A view of what began the relationship, not of their first ad.',
            self::PaidLastClick => 'As recorded, counting only conversions whose link is a paid one — '
                .'a platform click identifier, or a paid medium.',
            self::Linear => 'Would share credit equally across every marketing touch before the '
                .'conversion.',
            self::PositionBased => 'Would weight the first and last touch most heavily.',
            self::TimeDecay => 'Would weight touches by how close they were to the conversion.',
        };
    }

    /**
     * The models that can be computed from what this product holds.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isAvailable()));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
