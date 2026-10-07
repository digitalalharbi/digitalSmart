<?php

declare(strict_types=1);

namespace App\Domain\Creative;

use DateTimeImmutable;

/**
 * One placement's recorded creative specification, and the checking of an asset against it.
 *
 * **Recorded, not known.** The ad platforms publish their creative requirements in human-readable help
 * pages rendered by JavaScript, not in anything a program can read; the third-party guides that
 * aggregate them disagree with each other and go stale silently. So a specification in this product is
 * data somebody entered, with the page they read and the date they read it, and every verdict computed
 * against one carries that date. See `SpecProvenance` and docs/CREATIVE.md.
 *
 * The consequence is that **most placements have no specification**, and the product says so rather than
 * passing everything. That is useful in itself: it tells an agency which placements they are publishing
 * to without a safety net.
 */
final readonly class CreativeSpec
{
    /**
     * @param  array<string, SpecBound>  $bounds  keyed by `SpecRequirement` value
     */
    public function __construct(
        public string $provider,
        public string $placement,
        public AssetKind $kind,
        public array $bounds,
        public SpecProvenance $provenance,
    ) {}

    public function bound(SpecRequirement $requirement): ?SpecBound
    {
        return $this->bounds[$requirement->value] ?? null;
    }

    /**
     * Check an asset against this specification.
     *
     * Only the requirements this kind of asset *can* have are considered: a duration check on an image is
     * not unchecked, it is inapplicable, and filling a review with warnings nobody can act on is how
     * reviewers learn to ignore warnings.
     */
    public function check(AssetMeasurements $asset, DateTimeImmutable $now): SpecReport
    {
        $checks = [];

        foreach ($this->kind->measurable() as $requirement) {
            $bound = $this->bound($requirement);

            if ($bound === null) {
                // The specification says nothing about this property. Not a gap in the asset.
                continue;
            }

            $checks[] = $this->checkOne($requirement, $bound, $asset, $now);
        }

        if ($checks === []) {
            /*
             * A specification exists and constrains nothing this asset kind has. Honest, and rare: it
             * usually means the recorded row named requirements without numbers.
             */
            return new SpecReport(
                verdict: SpecVerdict::Unchecked,
                checks: [],
                provider: $this->provider,
                placement: $this->placement,
                provenance: $this->provenance,
                specAgeDays: $this->provenance->ageInDays($now),
            );
        }

        return new SpecReport(
            verdict: SpecVerdict::weakest(array_map(
                static fn (SpecCheck $check): SpecVerdict => $check->verdict,
                $checks,
            )),
            checks: $checks,
            provider: $this->provider,
            placement: $this->placement,
            provenance: $this->provenance,
            specAgeDays: $this->provenance->ageInDays($now),
        );
    }

    private function checkOne(
        SpecRequirement $requirement,
        SpecBound $bound,
        AssetMeasurements $asset,
        DateTimeImmutable $now,
    ): SpecCheck {
        [$verdict, $measured] = match ($requirement) {
            SpecRequirement::Width => [
                $bound->judge($asset->widthPx === null ? null : (float) $asset->widthPx),
                $asset->widthPx === null ? null : (string) $asset->widthPx,
            ],
            SpecRequirement::Height => [
                $bound->judge($asset->heightPx === null ? null : (float) $asset->heightPx),
                $asset->heightPx === null ? null : (string) $asset->heightPx,
            ],
            SpecRequirement::FileSize => [
                $bound->judge($asset->fileSizeBytes === null ? null : (float) $asset->fileSizeBytes),
                $asset->fileSizeBytes === null ? null : (string) $asset->fileSizeBytes,
            ],
            SpecRequirement::Duration => [
                $bound->judge($asset->durationSeconds),
                $asset->durationSeconds === null ? null : (string) $asset->durationSeconds,
            ],
            SpecRequirement::TextLength => [
                $bound->judge($asset->textLength === null ? null : (float) $asset->textLength),
                $asset->textLength === null ? null : (string) $asset->textLength,
            ],
            SpecRequirement::Format => [
                $bound->judgeOneOf($asset->format),
                $asset->format,
            ],
            SpecRequirement::AspectRatio => [
                $bound->judgeRatio(
                    $asset->widthPx === null ? null : (float) $asset->widthPx,
                    $asset->heightPx === null ? null : (float) $asset->heightPx,
                ),
                $asset->describeRatio(),
            ],
        };

        return new SpecCheck(
            requirement: $requirement,
            // A stale specification downgrades a pass and leaves a failure alone. See SpecProvenance.
            verdict: $this->provenance->temper($verdict, $now),
            measured: $measured,
            expected: $bound->describe($requirement->unit()),
            detail: $verdict === SpecVerdict::Unchecked
                ? ($asset->unmeasuredReason ?? 'This product could not measure it.')
                : null,
        );
    }
}
