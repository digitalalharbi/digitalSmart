<?php

declare(strict_types=1);

namespace App\Application\Attribution\DTO;

/**
 * How much two models disagree about one campaign.
 *
 * The finding, rather than a diagnostic. When last-recorded credits a campaign with 180,000 and
 * first-recorded credits it with 40,000, the useful statement is not either number — it is that
 * choosing a model moved this campaign's result by a factor of four, so nobody should present either
 * figure as what the campaign earned.
 */
final readonly class ModelDisagreement
{
    /**
     * @param  array<string, int|null>  $revenueMinorByModel  keyed by the model's value; null means the
     *                                                        campaign did not appear in that model's rows
     */
    public function __construct(
        public string $label,
        public array $revenueMinorByModel,
        public ?string $currency = null,
    ) {}

    /** The largest figure any model gives this campaign, or null. */
    public function highestMinor(): ?int
    {
        $values = array_filter($this->revenueMinorByModel, static fn (mixed $v): bool => is_int($v));

        return $values === [] ? null : max($values);
    }

    public function lowestMinor(): ?int
    {
        $values = array_filter($this->revenueMinorByModel, static fn (mixed $v): bool => is_int($v));

        return $values === [] ? null : min($values);
    }

    /**
     * How far apart the models are, as a share of the largest, 0–100, or null.
     *
     * Of the largest rather than the smallest, so a campaign one model credits with nothing reads as
     * 100% disagreement instead of an infinity. Null when there is nothing to compare — a single
     * model, or no revenue — because a spread of zero and "only one model ran" are different facts.
     */
    public function spreadPercent(): ?float
    {
        $highest = $this->highestMinor();
        $lowest = $this->lowestMinor();

        if ($highest === null || $lowest === null || $highest <= 0 || count($this->revenueMinorByModel) < 2) {
            return null;
        }

        return round((($highest - $lowest) / $highest) * 100, 1);
    }

    /**
     * Whether the models disagree enough that quoting one of them would mislead.
     *
     * A fifth of the figure. Below that the choice of model is a detail; above it, the choice is the
     * story, and a report naming one campaign as the month's winner may be naming the wrong one.
     */
    public function isMaterial(): bool
    {
        $spread = $this->spreadPercent();

        return $spread !== null && $spread >= 20.0;
    }
}
