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
     * A container has never been published, so nothing in it is deployed.
     *
     * The commonest tag-manager failure and the hardest to see: the workspace looks complete,
     * every tag is configured, and the live site has no container version at all.
     */
    public const string CONTAINER_NEVER_PUBLISHED = 'container_never_published';

    /** The container has edits nobody has published, so the live site is running older rules. */
    public const string CONTAINER_UNPUBLISHED_CHANGES = 'container_unpublished_changes';

    /** A tag in the live container is paused, so it is deployed and does nothing. */
    public const string TAG_PAUSED = 'tag_paused';

    /** A tag has no firing trigger, so it can never fire. */
    public const string TAG_WITHOUT_TRIGGER = 'tag_without_trigger';

    /** A tag fires on a trigger the live version does not contain. */
    public const string TAG_TRIGGER_MISSING = 'tag_trigger_missing';

    /**
     * No tag in the live container carries the measurement id of the property bound to this client.
     *
     * A warning, never a critical. A container can load a property through a custom template, a
     * server-side container or a variable this product cannot resolve, so absence here is
     * evidence worth showing a person rather than proof of a fault.
     */
    public const string MEASUREMENT_ID_NOT_IN_CONTAINER = 'measurement_id_not_in_container';

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
