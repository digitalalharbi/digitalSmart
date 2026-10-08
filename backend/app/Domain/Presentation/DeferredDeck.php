<?php

declare(strict_types=1);

namespace App\Domain\Presentation;

/**
 * A deck this product does not compose, and why.
 *
 * Published rather than omitted, and rather than offered as a template that produces three empty slides. The same
 * argument the portal makes about the surfaces it will not expose and the assistant makes about the mode it does
 * not yet have: "not built" and "deliberately not from this data" are different answers, and only one of them
 * should be given twice.
 */
enum DeferredDeck: string
{
    case CampaignProposal = 'campaign_proposal';

    public function label(): string
    {
        return match ($this) {
            self::CampaignProposal => 'Campaign proposal',
        };
    }

    public function because(): string
    {
        return match ($this) {
            self::CampaignProposal => 'A proposal is a document an agency writes: the objective, the audience, '
                .'the creative idea and what success would look like. None of those is in this product at the '
                .'point a deck is generated, and composing one from last month\'s delivery would produce a '
                .'proposal shaped entirely by what was easiest to measure. The slide shapes and the theme are '
                .'built and waiting; what is missing is the brief.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
