<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Contract;

use App\Application\Intelligence\DTO\NarrativeBrief;

/**
 * Writing prose from a brief the product has already worked out.
 *
 * Deliberately the narrowest port in this codebase. There is one method, it takes findings the product computed
 * and returns sentences, and there is no way to ask it a question, fetch anything, or decide anything. An
 * assistant whose model chose which figures to look at would be an assistant whose denominators were chosen by a
 * language model, and a wrong denominator is the one error in analytics nobody catches.
 *
 * Implementations must not be relied on to behave. The caller guards the output — see `NarrativeGuard` — because
 * "the model was instructed not to" is not a control.
 */
interface LanguageModel
{
    /** Whether this implementation can actually reach a model. */
    public function isConfigured(): bool;

    /** What to tell the reader wrote their narrative. */
    public function name(): string;

    /**
     * @throws \App\Application\Intelligence\Exception\LanguageModelUnavailable
     */
    public function write(NarrativeBrief $brief): string;
}
