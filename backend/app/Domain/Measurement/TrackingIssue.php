<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

/**
 * One thing wrong with a project's measurement, and what to do about it.
 *
 * Every issue carries its own evidence. A tracking centre that says "purchase event
 * missing" without saying *how it knows* is a tracking centre people stop believing after
 * the first false alarm — and the false alarm is guaranteed, because this product can only
 * see what a provider reports to it.
 *
 * The codes are stable strings rather than a growing enum: an issue type is data about a
 * diagnosis, it is referenced by the interface for a translation, and adding one should not
 * be a schema change. They are listed as constants so there is still one place to read them.
 */
final readonly class TrackingIssue
{
    /** A project has no measurement source at all. Nothing can be reported. */
    public const string NO_SOURCE = 'no_source';

    /** A source has never been read successfully. */
    public const string NEVER_SYNCED = 'never_synced';

    /** A source's last successful read is old enough to distrust. */
    public const string STALE_SYNC = 'stale_sync';

    /** Reachable and reporting nothing: working, and measuring nothing. */
    public const string NO_RECENT_EVENTS = 'no_recent_events';

    /** A conversion the project declared primary has never been seen. */
    public const string PRIMARY_CONVERSION_UNSEEN = 'primary_conversion_unseen';

    /** A raw event name arrives that nobody has mapped, so it is counted as nothing. */
    public const string UNMAPPED_EVENT = 'unmapped_event';

    /** No conversion is marked primary, so no report can say what success means. */
    public const string NO_PRIMARY_CONVERSION = 'no_primary_conversion';

    /** Purchases are recorded with no value, so revenue cannot be reported. */
    public const string PURCHASE_WITHOUT_VALUE = 'purchase_without_value';

    /** Two definitions claim the same raw event, so a count would double. */
    public const string DUPLICATE_MAPPING = 'duplicate_mapping';

    /**
     * An intent signal is being counted as a conversion.
     *
     * A WhatsApp click is not a conversation and a form start is not a submission. This is
     * reported rather than refused: an agency may genuinely want to count clicks, as long as
     * nobody later reads the number as leads.
     */
    public const string INTENT_SIGNAL_AS_CONVERSION = 'intent_signal_as_conversion';

    /**
     * @param  array<string, mixed>  $evidence  the facts the diagnosis rests on
     */
    public function __construct(
        public string $code,
        public TrackingSeverity $severity,
        public string $summary,
        /** What somebody should do, in a sentence. Null where there is nothing to do. */
        public ?string $remedy = null,
        /** The thing this is about: a source id, a definition id, a raw event name. */
        public ?string $subject = null,
        public array $evidence = [],
    ) {}
}
