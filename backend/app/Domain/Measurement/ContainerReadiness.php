<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

/**
 * Whether a tag container is actually doing anything.
 *
 * A container is not a source of figures — it reports configuration, not events — so it cannot
 * be judged by freshness. It is judged by whether what somebody built is deployed and able to
 * fire, which is a different question and one no chart anywhere else in this product can answer.
 *
 * **What this will and will not conclude.**
 *
 * Every finding below rests on a structural fact the API states outright: a container has no
 * published version, a tag is flagged paused, a tag lists no firing trigger, a tag names a
 * trigger the version does not contain. Those are not interpretations.
 *
 * What it will *not* do is read meaning into a tag's name or its type string. "GA4 – Purchase"
 * is a label somebody typed; it may fire on a thank-you page, on every page, or never. A product
 * that reported "purchase tracking is configured" because a tag was called that would be
 * confidently wrong in the one place an agency cannot afford to be, and the client would have no
 * way to tell. So the tag's name appears only as the subject of a finding — never as grounds for
 * one.
 *
 * The measurement-id check is the one inference, and it is deliberately framed as absence of
 * evidence rather than evidence of absence: a container can load a property through a custom
 * template, a lookup variable or a server-side container, none of which this product can see.
 */
final readonly class ContainerReadiness
{
    /**
     * Above this, a trigger id is one of Tag Manager's own built-ins.
     *
     * Every container is created with All Pages, Consent Initialization and Initialization
     * triggers. They cannot be deleted and they are **not returned by the triggers list**, so a
     * tag firing on one looks, to a naive comparison, exactly like a tag pointing at a trigger
     * that no longer exists — and nearly every tag in a real container fires on All Pages.
     *
     * Container-defined ids are small integers assigned per container (1, 31, 32, …); the
     * built-ins sit just below the 32-bit signed maximum. The threshold is deliberately far below
     * them and far above anything a container will reach on its own, so that **the failure mode is
     * silence rather than a wrong accusation**: a missed dangling trigger costs one warning, while
     * a false one on every tag in the container would empty this page of meaning on first use.
     */
    private const int BUILT_IN_TRIGGER_FLOOR = 2_000_000_000;

    /**
     * @param  list<string>  $expectedMeasurementIds  ids the client's analytics sources are known by
     * @return list<TrackingIssue>
     */
    public static function assess(ContainerFacts $container, array $expectedMeasurementIds = []): array
    {
        if (! $container->hasLiveVersion) {
            /*
             * One finding and nothing else. Reporting paused tags and missing triggers inside a
             * container that was never published would bury the only fact that matters: none of it
             * is on the site, so none of the rest is worth a person's attention yet.
             */
            return [new TrackingIssue(
                code: TrackingIssue::CONTAINER_NEVER_PUBLISHED,
                severity: TrackingSeverity::Critical,
                summary: sprintf('%s has never been published, so none of its tags are live.', $container->name),
                remedy: 'Publish the container, then check the tracking page again.',
                subject: $container->containerId,
                evidence: ['public_id' => $container->publicId],
            )];
        }

        $issues = [];

        foreach ($container->tags as $tag) {
            foreach (self::tagIssues($tag, $container) as $issue) {
                $issues[] = $issue;
            }
        }

        if ($container->hasUnpublishedChanges) {
            $issues[] = new TrackingIssue(
                code: TrackingIssue::CONTAINER_UNPUBLISHED_CHANGES,
                severity: TrackingSeverity::Warning,
                /*
                 * A warning, not a fault. Unpublished work is normal — somebody is mid-change.
                 * It earns a place here because it explains the commonest confused conversation
                 * in an agency: the tag is "set up" and the figures have not moved.
                 */
                summary: sprintf('%s has edits that have not been published.', $container->name),
                remedy: 'Publish them, or expect the live site to keep using the previous version.',
                subject: $container->containerId,
                evidence: ['live_version_id' => $container->liveVersionId],
            );
        }

        foreach (self::missingMeasurementIds($container, $expectedMeasurementIds) as $missing) {
            $issues[] = new TrackingIssue(
                code: TrackingIssue::MEASUREMENT_ID_NOT_IN_CONTAINER,
                severity: TrackingSeverity::Warning,
                summary: sprintf(
                    'No tag in the published version of %s refers to %s.',
                    $container->name,
                    $missing,
                ),
                /*
                 * Phrased as something to confirm rather than something to fix. The container may
                 * well be loading the property through a template or a variable this product
                 * cannot read, and telling somebody to "add the tag" when it is already there is
                 * how a tracking page loses its authority.
                 */
                remedy: 'Confirm how this property is loaded — a custom template or server-side container would not show here.',
                subject: $missing,
                evidence: [
                    // The ids it *did* find, so the reader can judge the claim rather than trust it.
                    'measurement_ids_found' => $container->measurementIdsFound,
                    'live_version_id' => $container->liveVersionId,
                ],
            );
        }

        usort(
            $issues,
            static fn (TrackingIssue $a, TrackingIssue $b): int => $a->severity->rank() <=> $b->severity->rank(),
        );

        return $issues;
    }

    /** @return list<TrackingIssue> */
    private static function tagIssues(TagFacts $tag, ContainerFacts $container): array
    {
        $issues = [];

        if ($tag->firingTriggerIds === []) {
            $issues[] = new TrackingIssue(
                code: TrackingIssue::TAG_WITHOUT_TRIGGER,
                severity: TrackingSeverity::Critical,
                /*
                 * Structural and unarguable: a tag with no firing trigger cannot fire, whatever it
                 * is called and whatever it was meant to do.
                 */
                summary: sprintf('"%s" has no firing trigger, so it never fires.', $tag->name),
                remedy: 'Give it a trigger, or remove it so the container says what it does.',
                subject: $tag->tagId,
                evidence: ['tag_type' => $tag->type],
            );
        }

        $dangling = array_values(array_filter(
            array_diff($tag->firingTriggerIds, $container->triggerIds),
            static fn (string $id): bool => ! self::isBuiltInTrigger($id),
        ));

        if ($dangling !== []) {
            $issues[] = new TrackingIssue(
                code: TrackingIssue::TAG_TRIGGER_MISSING,
                severity: TrackingSeverity::Critical,
                summary: sprintf(
                    '"%s" fires on a trigger that is not in the published version.',
                    $tag->name,
                ),
                remedy: 'Republish the container, or point the tag at a trigger that exists.',
                subject: $tag->tagId,
                evidence: ['missing_trigger_ids' => $dangling],
            );
        }

        if ($tag->isPaused) {
            $issues[] = new TrackingIssue(
                code: TrackingIssue::TAG_PAUSED,
                severity: TrackingSeverity::Warning,
                /*
                 * Deployed and doing nothing — which looks identical to working from everywhere
                 * except here, and is usually left over from somebody debugging.
                 */
                summary: sprintf('"%s" is published but paused, so it does nothing.', $tag->name),
                remedy: 'Unpause it if it should be running, or delete it.',
                subject: $tag->tagId,
                evidence: ['tag_type' => $tag->type],
            );
        }

        return $issues;
    }

    /**
     * Whether an id belongs to one of Tag Manager's undeletable built-in triggers.
     *
     * A non-numeric id is treated as built-in too — meaning "do not accuse" — because an id this
     * code cannot parse is one it does not understand well enough to call broken.
     */
    private static function isBuiltInTrigger(string $triggerId): bool
    {
        return ! ctype_digit($triggerId) || (int) $triggerId >= self::BUILT_IN_TRIGGER_FLOOR;
    }

    /**
     * Expected ids the published version does not mention anywhere.
     *
     * Matched against values found in *any* tag parameter, at any depth, under any key. That is
     * deliberate: which parameter key holds a measurement id depends on the tag type, and this
     * product does not claim to know the internal parameter schema of every GTM template. Looking
     * for the id itself is a fact about the container; assuming a key would be a guess.
     *
     * @param  list<string>  $expected
     * @return list<string>
     */
    private static function missingMeasurementIds(ContainerFacts $container, array $expected): array
    {
        $found = array_map('strtoupper', $container->measurementIdsFound);
        $missing = [];

        foreach ($expected as $id) {
            if ($id !== '' && ! in_array(strtoupper($id), $found, true)) {
                $missing[] = $id;
            }
        }

        return $missing;
    }
}
