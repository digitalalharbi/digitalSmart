<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * A hash of exactly what is being proposed.
 *
 * It exists so a decision can **name what it decided on**. A decider sends back the fingerprint their screen
 * showed; if the proposal was amended in between, the decision is refused and they are shown what it says now.
 * Without it the race is silent and the record claims somebody approved a proposal they never read — which is
 * the same defect a creative approval avoids by attaching to a version rather than to a request.
 *
 * The hash is over a **canonical** form: keys sorted at every depth, so two proposals that differ only in the
 * order a client happened to serialise them produce one fingerprint. Without that the rule would fire on a
 * key reordering and teach people to ignore it.
 */
final readonly class ProposalFingerprint
{
    private function __construct(public string $value) {}

    /** @param array<string, mixed> $proposal */
    public static function of(array $proposal): self
    {
        return new self(hash('sha256', self::canonical($proposal)));
    }

    public static function fromStored(string $value): self
    {
        return new self($value);
    }

    public function matches(?string $other): bool
    {
        /*
         * A missing fingerprint never matches. A caller that omits it has not told us what it was looking at,
         * and treating silence as agreement is the whole failure this class prevents.
         */
        return $other !== null && hash_equals($this->value, $other);
    }

    /**
     * Enough of the hash to show a person, which is all a person needs.
     *
     * Twelve characters: long enough that two proposals in one queue will not collide in front of a reader,
     * short enough to be quoted in a message. Comparison always uses the whole value.
     */
    public function short(): string
    {
        return substr($this->value, 0, 12);
    }

    /** @param array<array-key, mixed> $value */
    private static function canonical(array $value): string
    {
        $sorted = self::sort($value);

        return json_encode($sorted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sort(array $value): array
    {
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sort($item);
            }
        }

        if (! $isList) {
            // Lists keep their order, because the order of a list is part of what it says.
            ksort($value);
        }

        return $value;
    }
}
