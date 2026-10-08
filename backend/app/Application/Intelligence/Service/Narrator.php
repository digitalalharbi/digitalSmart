<?php

declare(strict_types=1);

namespace App\Application\Intelligence\Service;

use App\Application\Intelligence\Contract\LanguageModel;
use App\Application\Intelligence\DTO\Narrative;
use App\Application\Intelligence\DTO\NarrativeBrief;
use App\Application\Intelligence\Exception\LanguageModelUnavailable;

/**
 * Gets the prose written, and decides whether to use it.
 *
 * The order of operations is the whole design. **The product's own narrative is composed first, every time**, so
 * there is always something to serve — and then a model is asked, and its answer is used only if it survives the
 * guard. A reader never waits on a third party to find out what their own figures did, and a model that is
 * missing, slow, rate-limited or wrong costs them a better-written paragraph and nothing else.
 *
 * Three outcomes, and the response says which:
 *
 *  - **No model configured, or it could not be reached** — the product's sentences, attributed to the product.
 *  - **A model answered and the guard passed it** — the model's sentences, attributed to the model.
 *  - **A model answered and the guard refused it** — the product's sentences, attributed to the product, *and*
 *    the refusal reported. A module that quietly swallowed that would be a module whose guard nobody could tell
 *    was working.
 */
final readonly class Narrator
{
    public function __construct(
        private LanguageModel $model,
        private NarrativeGuard $guard,
        private DeterministicNarrative $product,
    ) {}

    public function narrate(NarrativeBrief $brief): Narrative
    {
        // Composed first, unconditionally. Nothing below can leave a reader with no narrative.
        $fallback = $this->product->write($brief);

        if (! $this->model->isConfigured()) {
            return Narrative::fromProduct($fallback);
        }

        try {
            $written = $this->model->write($brief);
        } catch (LanguageModelUnavailable) {
            /*
             * Swallowed on purpose. The findings are already complete, so an unreachable model is a degraded
             * paragraph rather than a failed request — and the attribution tells the reader which they have.
             */
            return Narrative::fromProduct($fallback);
        }

        $trimmed = trim($written);

        if ($trimmed === '') {
            return Narrative::fromProduct($fallback);
        }

        $verdict = $this->guard->inspect($trimmed, $brief->allowedFigures);

        if ($verdict['ok'] !== true) {
            return Narrative::refused(
                $fallback,
                $this->model->name(),
                sprintf(
                    'The model\'s answer stated %s, which the evidence does not contain.',
                    implode(', ', array_slice($verdict['offending'], 0, 5)),
                ),
            );
        }

        return Narrative::fromModel($trimmed, $this->model->name());
    }
}
