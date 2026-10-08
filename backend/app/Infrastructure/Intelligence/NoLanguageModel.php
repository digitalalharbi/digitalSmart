<?php

declare(strict_types=1);

namespace App\Infrastructure\Intelligence;

use App\Application\Intelligence\Contract\LanguageModel;
use App\Application\Intelligence\DTO\NarrativeBrief;
use App\Application\Intelligence\Exception\LanguageModelUnavailable;

/**
 * No model at all, which is this product's default.
 *
 * Bound whenever no API key is configured — which is every environment in this repository, because a model key is
 * a production secret this repository cannot hold. The assistant works without it: every finding and every figure
 * is computed by the product, and `Narrator` composes the prose itself.
 *
 * It exists as a class rather than as a null check because `isConfigured()` returning false is a *fact a reader is
 * told*: the assistant's answers say who wrote them, and "the product" is the honest attribution when nothing
 * else did. A silent null would make the two cases indistinguishable.
 */
final readonly class NoLanguageModel implements LanguageModel
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'none configured';
    }

    public function write(NarrativeBrief $brief): string
    {
        throw LanguageModelUnavailable::notConfigured();
    }
}
