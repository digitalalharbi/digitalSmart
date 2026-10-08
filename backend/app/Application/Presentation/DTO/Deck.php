<?php

declare(strict_types=1);

namespace App\Application\Presentation\DTO;

use App\Domain\Advertising\MetricSource;
use App\Domain\Presentation\DeckKind;
use App\Domain\Presentation\PresentationTheme;
use DateTimeImmutable;

/**
 * A deck, ready to render.
 *
 * Carries four things a reader of the finished file would otherwise have to take on trust.
 *
 * **When it was prepared.** A deck is a file that gets forwarded. Six weeks later nobody remembers which Tuesday
 * it describes, and a deck with no date is a deck somebody quotes.
 *
 * **The weakest source behind anything in it.** Rolled up the same way every other surface in this product rolls
 * it up: a deck built from one sandbox figure is a sandbox deck, whatever the other forty were.
 *
 * **Whether the theme is the agency's or a placeholder.** `theme->isProvisional` is true until a reference deck
 * exists, and it is said on the deck rather than in a README — a placeholder presented as a brand is worse than
 * an obvious placeholder.
 *
 * **The client's name, once.** Every renderer puts it where that renderer puts it; the composer does not decide
 * layout.
 */
final readonly class Deck
{
    /**
     * @param  list<Slide>  $slides
     */
    public function __construct(
        public DeckKind $kind,
        public PresentationTheme $theme,
        public string $clientName,
        public string $title,
        public string $subtitle,
        public array $slides,
        public MetricSource $source,
        public DateTimeImmutable $preparedAt,
        /** True when the figures may be shown to the client this deck is about. */
        public bool $isClientSafe,
    ) {}

    /** A filename somebody can find again. Dated, because a deck is a file that gets forwarded. */
    public function filename(string $extension): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $this->clientName.' '.$this->kind->label()) ?? 'deck';

        return sprintf('%s-%s.%s', trim(strtolower($slug), '-'), $this->preparedAt->format('Y-m-d'), $extension);
    }
}
