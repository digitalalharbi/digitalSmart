<?php

declare(strict_types=1);

namespace App\Domain\Creative;

/**
 * Everything checking one asset against one placement established.
 *
 * The overall verdict is the **weakest** of the checks, not an average and not a majority. Eleven passes
 * and one unchecked dimension is an unchecked asset: a reader deciding whether to publish needs the floor
 * of what was established, and a green tick over a set containing an unknown is the specific way a
 * creative tool tells a lie nobody catches until the platform rejects the upload.
 */
final readonly class SpecReport
{
    /** @param list<SpecCheck> $checks */
    public function __construct(
        public SpecVerdict $verdict,
        public array $checks,
        public string $provider,
        public string $placement,
        /** Null when nobody has recorded a specification for this placement. */
        public ?SpecProvenance $provenance = null,
        /** How old the specification was when this was computed, in days. */
        public ?int $specAgeDays = null,
    ) {}

    /**
     * A placement nobody has recorded a specification for.
     *
     * Its own constructor because it is the commonest outcome and the one most likely to be papered over.
     * It carries no checks, because none were performed — an empty list rather than a list of passes.
     */
    public static function noSpec(string $provider, string $placement): self
    {
        return new self(SpecVerdict::NoSpec, [], $provider, $placement);
    }

    /** Only a measured failure stops a publish. See `SpecVerdict::blocksPublishing()`. */
    public function blocksPublishing(): bool
    {
        return $this->verdict->blocksPublishing();
    }

    /**
     * The checks that failed, which is what a producer needs to act on.
     *
     * @return list<SpecCheck>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (SpecCheck $check): bool => $check->verdict === SpecVerdict::Fail,
        ));
    }

    /**
     * The checks that established nothing, which is what a reviewer needs to know about.
     *
     * @return list<SpecCheck>
     */
    public function unchecked(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (SpecCheck $check): bool => $check->verdict === SpecVerdict::Unchecked,
        ));
    }
}
