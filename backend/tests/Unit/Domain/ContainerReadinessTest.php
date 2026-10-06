<?php

declare(strict_types=1);

use App\Domain\Measurement\ContainerFacts;
use App\Domain\Measurement\ContainerReadiness;
use App\Domain\Measurement\TagFacts;
use App\Domain\Measurement\TrackingIssue;
use App\Domain\Measurement\TrackingSeverity;

/*
|--------------------------------------------------------------------------
| Container readiness
|--------------------------------------------------------------------------
|
| A container cannot be judged by freshness — it reports configuration, not events — so it is
| judged by whether what somebody built is deployed and able to fire. Most of these tests are
| about the boundary of that claim: what it concludes from structure, and what it refuses to
| conclude from a tag's name.
|
*/

/** @param list<string> $triggers */
function tag(
    string $name = 'GA4 — purchase',
    string $tagId = '15',
    string $type = 'gaawe',
    bool $paused = false,
    array $triggers = ['31'],
): TagFacts {
    return new TagFacts(
        tagId: $tagId,
        name: $name,
        type: $type,
        isPaused: $paused,
        firingTriggerIds: $triggers,
    );
}

/**
 * @param  list<TagFacts>  $tags
 * @param  list<string>  $triggerIds
 * @param  list<string>  $measurementIds
 */
function container(
    array $tags = [],
    array $triggerIds = ['31'],
    bool $live = true,
    bool $unpublished = false,
    array $measurementIds = ['G-7QK2M4XZPL'],
): ContainerFacts {
    return new ContainerFacts(
        containerId: '191002277',
        name: 'Noon Retail — Web',
        publicId: 'GTM-NR4QX8V',
        hasLiveVersion: $live,
        liveVersionId: $live ? '18' : null,
        hasUnpublishedChanges: $unpublished,
        tags: $tags,
        triggerIds: $triggerIds,
        measurementIdsFound: $measurementIds,
    );
}

/**
 * @param  list<TrackingIssue>  $issues
 * @return list<string>
 */
function readinessCodes(array $issues): array
{
    return array_map(static fn (TrackingIssue $issue): string => $issue->code, $issues);
}

describe('a container that was never published', function (): void {
    it('reports that and nothing else', function (): void {
        $facts = container(
            tags: [tag(paused: true, triggers: [])],
            live: false,
            unpublished: true,
        );

        /*
         * One finding, deliberately. The container has a paused tag with no trigger and
         * unpublished changes — and reporting those would bury the only fact that matters: none of
         * it is on the site, so none of the rest is worth anybody's attention yet.
         */
        $issues = ContainerReadiness::assess($facts);

        expect($issues)->toHaveCount(1)
            ->and($issues[0]->code)->toBe(TrackingIssue::CONTAINER_NEVER_PUBLISHED)
            ->and($issues[0]->severity)->toBe(TrackingSeverity::Critical)
            ->and($issues[0]->evidence['public_id'])->toBe('GTM-NR4QX8V');
    });
});

describe('what it concludes from structure', function (): void {
    it('says nothing about a container whose tags can all fire', function (): void {
        expect(ContainerReadiness::assess(container(tags: [tag()])))->toBe([]);
    });

    it('calls a tag with no firing trigger critical', function (): void {
        $issues = ContainerReadiness::assess(container(tags: [tag(triggers: [])]));

        // Structural and unarguable: it cannot fire, whatever it is called.
        expect($issues[0]->code)->toBe(TrackingIssue::TAG_WITHOUT_TRIGGER)
            ->and($issues[0]->severity)->toBe(TrackingSeverity::Critical)
            ->and($issues[0]->subject)->toBe('15');
    });

    it('calls a paused tag a warning, because it is deployed and idle', function (): void {
        $issues = ContainerReadiness::assess(container(tags: [tag(paused: true)]));

        /*
         * Looks identical to working from everywhere except here, and is usually left over from
         * somebody debugging. A warning rather than a critical: it is a deliberate state somebody
         * chose, even if they then forgot.
         */
        expect($issues[0]->code)->toBe(TrackingIssue::TAG_PAUSED)
            ->and($issues[0]->severity)->toBe(TrackingSeverity::Warning);
    });

    it('calls a tag pointing at a missing trigger critical', function (): void {
        $issues = ContainerReadiness::assess(container(tags: [tag(triggers: ['31', '9001'])]));

        expect($issues[0]->code)->toBe(TrackingIssue::TAG_TRIGGER_MISSING)
            ->and($issues[0]->severity)->toBe(TrackingSeverity::Critical)
            ->and($issues[0]->evidence['missing_trigger_ids'])->toBe(['9001']);
    });

    it('reports unpublished changes without calling them a fault', function (): void {
        $issues = ContainerReadiness::assess(container(tags: [tag()], unpublished: true));

        /*
         * Unpublished work is normal — somebody is mid-change. It earns a place here because it
         * explains the commonest confused conversation in an agency: the tag is "set up" and the
         * figures have not moved.
         */
        expect($issues[0]->code)->toBe(TrackingIssue::CONTAINER_UNPUBLISHED_CHANGES)
            ->and($issues[0]->severity)->toBe(TrackingSeverity::Warning);
    });

    it('puts the worst findings first', function (): void {
        $issues = ContainerReadiness::assess(container(
            tags: [tag(paused: true), tag(name: 'GA4 — lead', tagId: '16', triggers: [])],
            unpublished: true,
        ));

        $ranks = array_map(static fn (TrackingIssue $i): int => $i->severity->rank(), $issues);
        $sorted = $ranks;
        sort($sorted);

        expect($ranks)->toBe($sorted)
            ->and($issues[0]->severity)->toBe(TrackingSeverity::Critical);
    });
});

describe('what it refuses to conclude', function (): void {
    it('never reads a tag\'s purpose from its name', function (): void {
        /*
         * The rule this whole class exists under. A container whose only tag is called
         * "GA4 — purchase" and which fires properly produces **no findings at all** — not
         * "purchase tracking configured", which would be a claim nothing here can support. The tag
         * may fire on a thank-you page, on every page, or on a button nobody clicks.
         */
        $issues = ContainerReadiness::assess(container(tags: [tag(name: 'GA4 — purchase')]));

        expect($issues)->toBe([]);
    });

    it('does not accuse a tag firing on a Tag Manager built-in trigger', function (): void {
        /*
         * The false positive that would have destroyed this feature on first use. Every container
         * is created with All Pages, Consent Initialization and Initialization triggers; they
         * cannot be deleted and they are **not returned by the triggers list**. Nearly every tag in
         * a real container fires on All Pages, so a naive comparison would report the whole
         * container as broken.
         */
        $issues = ContainerReadiness::assess(container(
            tags: [tag(triggers: ['2147479553'])],
            triggerIds: [],
        ));

        expect($issues)->toBe([]);
    });

    it('stays silent rather than guessing about a trigger id it cannot parse', function (): void {
        // An id this code does not understand is not an id it understands well enough to call
        // broken. The failure mode here is deliberately a missed warning, never a wrong one.
        $issues = ContainerReadiness::assess(container(
            tags: [tag(triggers: ['trigger-abc'])],
            triggerIds: [],
        ));

        expect(readinessCodes($issues))->not->toContain(TrackingIssue::TAG_TRIGGER_MISSING);
    });
});

describe('the measurement id cross-check', function (): void {
    it('says nothing when the published container refers to the expected property', function (): void {
        $issues = ContainerReadiness::assess(
            container(tags: [tag()], measurementIds: ['G-7QK2M4XZPL', 'AW-882440115']),
            ['G-7QK2M4XZPL'],
        );

        expect($issues)->toBe([]);
    });

    it('matches regardless of case', function (): void {
        $issues = ContainerReadiness::assess(
            container(tags: [tag()], measurementIds: ['g-7qk2m4xzpl']),
            ['G-7QK2M4XZPL'],
        );

        expect($issues)->toBe([]);
    });

    it('reports an expected id the container never mentions, as something to confirm', function (): void {
        $issues = ContainerReadiness::assess(
            container(tags: [tag()], measurementIds: ['G-SOMETHINGELSE']),
            ['G-7QK2M4XZPL'],
        );

        $issue = $issues[0];

        /*
         * A warning, never a critical, and phrased as a question rather than an instruction. The
         * container may be loading the property through a custom template, a lookup variable or a
         * server-side container — none of which this product can see. Telling somebody to "add the
         * tag" when it is already there is how a tracking page loses its authority.
         */
        expect($issue->code)->toBe(TrackingIssue::MEASUREMENT_ID_NOT_IN_CONTAINER)
            ->and($issue->severity)->toBe(TrackingSeverity::Warning)
            ->and($issue->remedy)->toContain('Confirm')
            // The evidence is the ids it did find, so the reader can judge the claim.
            ->and($issue->evidence['measurement_ids_found'])->toBe(['G-SOMETHINGELSE']);
    });

    it('checks nothing when no analytics property is registered for the client', function (): void {
        // Nothing to cross-check against. Structure only, which is the honest answer.
        $issues = ContainerReadiness::assess(container(tags: [tag()], measurementIds: []), []);

        expect($issues)->toBe([]);
    });

    it('reports each expected id it cannot find separately', function (): void {
        $issues = ContainerReadiness::assess(
            container(tags: [tag()], measurementIds: []),
            ['G-ONE', 'G-TWO'],
        );

        expect($issues)->toHaveCount(2)
            ->and(array_map(static fn (TrackingIssue $i): ?string => $i->subject, $issues))
            ->toBe(['G-ONE', 'G-TWO']);
    });
});
