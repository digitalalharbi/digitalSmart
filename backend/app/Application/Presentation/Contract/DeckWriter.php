<?php

declare(strict_types=1);

namespace App\Application\Presentation\Contract;

use App\Application\Presentation\DTO\Deck;

/**
 * Turning a deck into a file.
 *
 * One method, and it returns a path rather than bytes: a deck with a chart per slide is large enough that holding
 * it in memory to hand to a response is a decision rather than a default, and the Interface layer streams it.
 */
interface DeckWriter
{
    public function extension(): string;

    public function contentType(): string;

    /** @return string the path to the written file */
    public function write(Deck $deck): string;
}
