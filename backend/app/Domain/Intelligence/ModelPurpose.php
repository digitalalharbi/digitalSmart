<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * What a model is asked to do — which decides which model does it.
 *
 * Arithmetic never appears here: costs, targets, forecasts and thresholds are code. A model writes and sorts words
 * the product has already grounded in figures; the strong tier where the prose will be read by a client or a
 * director, the fast tier where the answer is one of a few labels and speed matters more than style.
 */
enum ModelPurpose: string
{
    /** A narrative over computed findings: weekly and monthly summaries, explanations of a change. */
    case Narrative = 'narrative';
    /** A draft plan or brief, built around figures the product supplies. */
    case Planning = 'planning';
    /** Which agents a question is for — one of a fixed list, validated after. */
    case Routing = 'routing';
    /** A one-line summary of a card already computed. */
    case Summary = 'summary';

    /** @return 'strong'|'fast' */
    public function tier(): string
    {
        return match ($this) {
            self::Narrative, self::Planning => 'strong',
            self::Routing, self::Summary => 'fast',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
