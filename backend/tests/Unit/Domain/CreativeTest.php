<?php

declare(strict_types=1);

use App\Domain\Creative\ApprovalOutcome;
use App\Domain\Creative\AssetKind;
use App\Domain\Creative\AssetMeasurements;
use App\Domain\Creative\CommentVisibility;
use App\Domain\Creative\CreativeSpec;
use App\Domain\Creative\CreativeState;
use App\Domain\Creative\Exception\InvalidCreativeTransition;
use App\Domain\Creative\SpecBound;
use App\Domain\Creative\SpecProvenance;
use App\Domain\Creative\SpecRequirement;
use App\Domain\Creative\SpecVerdict;

/*
|--------------------------------------------------------------------------
| Creative operations
|--------------------------------------------------------------------------
|
| Two things are tested here above all: that "valid" is never said about something nobody measured, and
| that an approval belongs to a file rather than to a request. Both have an obvious wrong version, and
| both wrong versions are what creative tools ship.
|
*/

const CREATIVE_NOW = '2026-10-07 12:00:00';

function creativeNow(): DateTimeImmutable
{
    return new DateTimeImmutable(CREATIVE_NOW);
}

/**
 * A specification recorded today, so staleness is only in play where a test asks for it.
 *
 * @param  array<string, SpecBound>  $bounds
 */
function recordedSpec(
    AssetKind $kind = AssetKind::Video,
    array $bounds = [],
    string $documentedAt = CREATIVE_NOW,
): CreativeSpec {
    return new CreativeSpec(
        provider: 'meta',
        placement: 'reels',
        kind: $kind,
        bounds: $bounds,
        provenance: new SpecProvenance(
            sourceUrl: 'https://www.facebook.com/business/ads-guide',
            documentedAt: new DateTimeImmutable($documentedAt),
            recordedBy: 'Layla',
        ),
    );
}

describe('the board refuses a move it does not allow', function (): void {
    it('will not send a draft straight to review', function (): void {
        /*
         * The board is the process. A draft that can jump to review is a board somebody works around,
         * and then the states stop describing what is happening.
         */
        expect(CreativeState::Draft->canMoveTo(CreativeState::InReview))->toBeFalse()
            ->and(CreativeState::Draft->canMoveTo(CreativeState::Briefed))->toBeTrue();

        expect(function (): void {
            CreativeState::Draft->assertCanMoveTo(CreativeState::InReview);
        })->toThrow(InvalidCreativeTransition::class);
    });

    it('names the legal moves when it refuses one', function (): void {
        try {
            CreativeState::Briefed->assertCanMoveTo(CreativeState::Approved);
            throw new RuntimeException('The transition should have been refused.');
        } catch (InvalidCreativeTransition $refusal) {
            /*
             * "Briefed work goes into production first" is a sentence somebody acts on. "Invalid
             * transition" is a sentence somebody files a bug about.
             */
            expect($refusal->allowed)->toContain('in_production')
                ->and($refusal->getMessage())->toContain('Briefed')
                ->and($refusal->getMessage())->toContain('Approved');
        }
    });

    it('sends changes-requested work back to production, not to review', function (): void {
        $next = CreativeState::ChangesRequested->allowedNext();

        expect($next)->toContain(CreativeState::InProduction);
        expect($next)->not->toContain(CreativeState::InReview);
    });

    it('lets approved work be reopened', function (): void {
        // A campaign gets extended, a size is added, a client changes their mind after signing off.
        expect(CreativeState::Approved->canMoveTo(CreativeState::InProduction))->toBeTrue();
    });

    it('counts everything except approved and cancelled as open', function (): void {
        expect(CreativeState::InReview->isOpen())->toBeTrue()
            ->and(CreativeState::ChangesRequested->isOpen())->toBeTrue()
            ->and(CreativeState::Approved->isOpen())->toBeFalse()
            ->and(CreativeState::Cancelled->isOpen())->toBeFalse();
    });

    it('orders the board columns the way the work moves', function (): void {
        $order = array_map(
            static fn (CreativeState $state): string => $state->value,
            CreativeState::board(),
        );

        expect($order)->toBe([
            'draft', 'briefed', 'in_production', 'in_review', 'changes_requested', 'approved', 'cancelled',
        ]);
    });
});

describe('an approval belongs to a file, not to a request', function (): void {
    it('sends a new version back for review wherever review has already happened', function (): void {
        /*
         * The rule that makes an approval trail worth having. A client approved a cut; the next cut is
         * unapproved until somebody says otherwise. An agency whose tool carried the approval forward has
         * published something nobody signed off, and the trail says they did sign off.
         */
        expect(CreativeState::Approved->requiresReReview())->toBeTrue()
            ->and(CreativeState::ChangesRequested->requiresReReview())->toBeTrue()
            ->and(CreativeState::InReview->requiresReReview())->toBeTrue()
            // Nothing has been reviewed yet, so a new version changes nothing about the state.
            ->and(CreativeState::InProduction->requiresReReview())->toBeFalse()
            ->and(CreativeState::Briefed->requiresReReview())->toBeFalse();
    });

    it('requires a reason to send work back and none to approve it', function (): void {
        expect(ApprovalOutcome::ChangesRequested->needsReason())->toBeTrue()
            ->and(ApprovalOutcome::Approved->needsReason())->toBeFalse();
    });

    it('maps each decision to the state it produces', function (): void {
        expect(ApprovalOutcome::Approved->state())->toBe(CreativeState::Approved)
            ->and(ApprovalOutcome::ChangesRequested->state())->toBe(CreativeState::ChangesRequested);
    });
});

describe('what a spec check will not claim', function (): void {
    it('says no spec rather than passing a placement nobody recorded', function (): void {
        /*
         * The commonest outcome, and the one a creative tool is most tempted to paper over. The platforms
         * do not publish their requirements in a form a program can read, so most placements have none
         * recorded — and saying so tells an agency which placements they are publishing to blind.
         */
        $report = App\Domain\Creative\SpecReport::noSpec('snapchat', 'single_image');

        expect($report->verdict)->toBe(SpecVerdict::NoSpec)
            ->and($report->checks)->toBe([])
            // And it does not stop anybody publishing: the gap is this product's, not the user's.
            ->and($report->blocksPublishing())->toBeFalse();
    });

    it('reports an unmeasured property as unchecked, never as a pass', function (): void {
        $spec = recordedSpec(bounds: [
            SpecRequirement::Duration->value => new SpecBound(min: 3, max: 90),
        ]);

        // A video whose duration this product could not read.
        $report = $spec->check(
            new AssetMeasurements(durationSeconds: null, unmeasuredReason: 'The file could not be opened.'),
            creativeNow(),
        );

        /*
         * Treating an unknown duration as zero would report it as comfortably inside a three-second
         * minimum — a green tick on a file the platform will reject.
         */
        expect($report->verdict)->toBe(SpecVerdict::Unchecked)
            ->and($report->unchecked())->toHaveCount(1)
            ->and($report->unchecked()[0]->detail)->toBe('The file could not be opened.');
    });

    it('takes the weakest verdict in a set, not the average', function (): void {
        $spec = recordedSpec(bounds: [
            SpecRequirement::Width->value => new SpecBound(min: 1080),
            SpecRequirement::Height->value => new SpecBound(min: 1920),
            SpecRequirement::Duration->value => new SpecBound(min: 3, max: 90),
        ]);

        // Two measured passes and one unknown.
        $report = $spec->check(new AssetMeasurements(widthPx: 1080, heightPx: 1920), creativeNow());

        /*
         * Eleven passes and one unknown is an unchecked asset. A reader deciding whether to publish needs
         * the floor of what was established, and a green tick over a set containing an unknown is the
         * specific lie this enum exists to prevent.
         */
        expect($report->verdict)->toBe(SpecVerdict::Unchecked);
    });

    it('does not warn about a requirement the asset kind cannot have', function (): void {
        $spec = recordedSpec(kind: AssetKind::Image, bounds: [
            SpecRequirement::Width->value => new SpecBound(min: 1080),
            SpecRequirement::Duration->value => new SpecBound(min: 3),
        ]);

        $report = $spec->check(new AssetMeasurements(widthPx: 1080, heightPx: 1080), creativeNow());

        /*
         * A duration check on an image is not unchecked — it is inapplicable. Filling a review with
         * warnings nobody can act on is how reviewers learn to ignore warnings.
         */
        expect($report->checks)->toHaveCount(1)
            ->and($report->verdict)->toBe(SpecVerdict::Pass);
    });

    it('downgrades a pass against a stale spec and leaves a failure alone', function (): void {
        $bounds = [SpecRequirement::Width->value => new SpecBound(min: 1080)];
        $stale = recordedSpec(bounds: $bounds, documentedAt: '2024-01-01 00:00:00');

        $inside = $stale->check(new AssetMeasurements(widthPx: 1080, heightPx: 1920), creativeNow());
        $outside = $stale->check(new AssetMeasurements(widthPx: 640, heightPx: 1136), creativeNow());

        /*
         * An asset inside a requirement nobody has checked since last year is not evidence that today's
         * platform agrees. An asset outside one is still worth looking at.
         */
        expect($inside->verdict)->toBe(SpecVerdict::Stale)
            ->and($outside->verdict)->toBe(SpecVerdict::Fail)
            ->and($outside->blocksPublishing())->toBeTrue();
    });

    it('only blocks a publish on a measured failure', function (): void {
        /*
         * Blocking on `Unchecked` or `NoSpec` would make this product's own gaps into the user's problem,
         * and they would learn to click past the warning — which is how a real failure gets clicked past.
         */
        expect(SpecVerdict::Fail->blocksPublishing())->toBeTrue()
            ->and(SpecVerdict::Unchecked->blocksPublishing())->toBeFalse()
            ->and(SpecVerdict::NoSpec->blocksPublishing())->toBeFalse()
            ->and(SpecVerdict::Stale->blocksPublishing())->toBeFalse();
    });

    it('calls an empty set of verdicts no-spec rather than a pass', function (): void {
        // Having checked nothing is not having found nothing wrong.
        expect(SpecVerdict::weakest([]))->toBe(SpecVerdict::NoSpec);
    });

    it('keeps both numbers so a producer can fix the file', function (): void {
        $spec = recordedSpec(bounds: [
            SpecRequirement::Height->value => new SpecBound(min: 1920),
        ]);

        $check = $spec->check(new AssetMeasurements(widthPx: 1080, heightPx: 1350), creativeNow())->checks[0];

        // "1350 against at least 1920 px" is actionable. "Fails height" is not.
        expect($check->measured)->toBe('1350')
            ->and($check->expected)->toBe('at least 1920 px');
    });
});

describe('measuring what the platforms actually constrain', function (): void {
    it('accepts a real export as 9:16 and rejects a square', function (): void {
        $bound = new SpecBound(allowed: ['9:16']);

        /*
         * Compared as a number with a tolerance, because 1080×1919 is 9:16 for every practical purpose and
         * an exact comparison would reject nearly every real export — while a loose one would accept a
         * square as a vertical.
         */
        expect($bound->judgeRatio(1080, 1920))->toBe(SpecVerdict::Pass)
            ->and($bound->judgeRatio(1080, 1919))->toBe(SpecVerdict::Pass)
            ->and($bound->judgeRatio(1080, 1080))->toBe(SpecVerdict::Fail);
    });

    it('matches a file format however it was capitalised', function (): void {
        $bound = new SpecBound(allowed: ['mp4', 'mov']);

        expect($bound->judgeOneOf('MP4'))->toBe(SpecVerdict::Pass)
            ->and($bound->judgeOneOf(' mov '))->toBe(SpecVerdict::Pass)
            ->and($bound->judgeOneOf('avi'))->toBe(SpecVerdict::Fail)
            // Absent rather than wrong: nothing was read off the file.
            ->and($bound->judgeOneOf(null))->toBe(SpecVerdict::Unchecked);
    });

    it('treats a bound with no number as unchecked', function (): void {
        $named = new SpecBound;

        /*
         * The page named a requirement without giving a number, which happens. Guessing one would mean
         * rejecting files the platform accepts.
         */
        expect($named->isStated())->toBeFalse()
            ->and($named->judge(1080.0))->toBe(SpecVerdict::Unchecked)
            ->and($named->describe('px'))->toBe('stated without a number');
    });

    it('describes a bound the way the platform stated it', function (): void {
        /*
         * "At least" and "between" are different claims, and flattening them into one shape would mean
         * inventing a maximum nobody published — which rejects files the platform would have accepted.
         */
        expect((new SpecBound(min: 1080))->describe('px'))->toBe('at least 1080 px')
            ->and((new SpecBound(max: 4_000_000_000))->describe('bytes'))->toBe('at most 4000000000 bytes')
            ->and((new SpecBound(min: 3, max: 90))->describe('seconds'))->toBe('3–90 seconds')
            ->and((new SpecBound(allowed: ['mp4', 'mov']))->describe())->toBe('mp4, mov');
    });

    it('reduces a measured size to the ratio a spec is written in', function (): void {
        expect((new AssetMeasurements(widthPx: 1080, heightPx: 1920))->describeRatio())->toBe('9:16')
            ->and((new AssetMeasurements(widthPx: 1080, heightPx: 1350))->describeRatio())->toBe('4:5')
            ->and((new AssetMeasurements(widthPx: 1080))->describeRatio())->toBeNull();
    });
});

describe('a specification has to say where it came from', function (): void {
    it('goes stale after six months', function (): void {
        $fresh = new SpecProvenance('https://example.test/spec', new DateTimeImmutable('2026-09-01'));
        $old = new SpecProvenance('https://example.test/spec', new DateTimeImmutable('2025-01-01'));

        /*
         * Long enough not to nag, short enough that a platform's quiet change is caught within a reporting
         * cycle rather than after a campaign has run on the wrong sizes.
         */
        expect($fresh->isStale(creativeNow()))->toBeFalse()
            ->and($old->isStale(creativeNow()))->toBeTrue()
            ->and($old->ageInDays(creativeNow()))->toBeGreaterThan(SpecProvenance::FRESH_DAYS);
    });
});

describe('who a comment is for', function (): void {
    it('keeps an internal note internal', function (): void {
        /*
         * "The client hated the last one, keep it safe" reaching that client ends a relationship. So
         * visibility is a field with no default and the client-facing surfaces filter on it rather than
         * trusting a caller to remember.
         */
        expect(CommentVisibility::Internal->isVisibleToClient())->toBeFalse()
            ->and(CommentVisibility::Client->isVisibleToClient())->toBeTrue()
            ->and(CommentVisibility::values())->toBe(['internal', 'client']);
    });
});
