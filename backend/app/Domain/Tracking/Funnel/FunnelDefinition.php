<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Funnel;

use App\Domain\Tracking\Exception\InvalidFunnel;

/**
 * Turns what a builder sent into stages that cannot mislead, or says why not.
 *
 * Two rules do the work. Stages are a closed list of sources (`FunnelSource`), and they must appear **in the order the
 * sources are declared** — visits, then the form, then the lead, then what happened to the lead. That is what makes the
 * denominator of every rate the previous stage, and what stops a funnel being arranged so that a flattering stage
 * divides by a smaller one.
 */
final class FunnelDefinition
{
    public const int MAX_STAGES = 12;

    /**
     * @param  list<array<string, mixed>>  $raw
     * @return list<FunnelStage>
     */
    public static function stagesFrom(array $raw): array
    {
        if (count($raw) < 2 || count($raw) > self::MAX_STAGES) {
            throw new InvalidFunnel(sprintf('A funnel has between 2 and %d stages.', self::MAX_STAGES));
        }

        $order = array_flip(FunnelSource::values());
        $previous = -1;
        $stages = [];

        foreach ($raw as $stage) {
            $source = FunnelSource::tryFrom((string) ($stage['source'] ?? '')) ?? throw new InvalidFunnel('A stage names a source this product does not record.');
            $rank = $order[$source->value];

            if ($rank <= $previous) {
                throw new InvalidFunnel(sprintf('"%s" is out of order or repeated: stages follow the journey, from the click to the sale.', $source->value));
            }

            $previous = $rank;
            $stages[] = new FunnelStage(
                $source,
                self::clip($stage['label_ar'] ?? null),
                self::clip($stage['label_en'] ?? null),
                (bool) ($stage['required'] ?? true),
            );
        }

        return $stages;
    }

    /** @return list<FunnelStage> */
    public static function standard(): array
    {
        return array_values(array_map(static fn (FunnelSource $s): FunnelStage => new FunnelStage($s, null, null, true), FunnelSource::defaultLeadFunnel()));
    }

    private static function clip(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 120) : null;
    }
}
