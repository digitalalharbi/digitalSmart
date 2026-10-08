<?php

declare(strict_types=1);

namespace App\Domain\Portal;

/**
 * A surface this product will not expose to a client, and why.
 *
 * It exists because "not implemented yet" and "deliberately never" are different answers, and only one of them
 * should be given twice. Every quarter somebody asks why the client cannot see the budget; without this, the answer
 * depends on who is asked.
 *
 * These are not permissions and there is no toggle behind them. The portal configuration surface lists them beside
 * the modules that *can* be exposed, with the sentence, so that the decision is visible rather than looking like an
 * oversight — the same reason the attribution surface shows the models it cannot compute instead of hiding them.
 *
 * A case moving out of here would be a product decision with a migration behind it: the surface would first need a
 * client-facing form enforced in the query, in the manner of {@see PortalModule}.
 */
enum WithheldModule: string
{
    case Budgets = 'budgets';

    case Tasks = 'tasks';

    case Approvals = 'approvals';

    case Leads = 'leads';

    case Attribution = 'attribution';

    case Journeys = 'journeys';

    case Audit = 'audit';

    public function label(): string
    {
        return match ($this) {
            self::Budgets => 'Budgets and pacing',
            self::Tasks => 'The work board',
            self::Approvals => 'Approvals',
            self::Leads => 'The Lead Center',
            self::Attribution => 'Attribution',
            self::Journeys => 'Customer journeys',
            self::Audit => 'The audit trail',
        };
    }

    public function because(): string
    {
        return match ($this) {
            self::Budgets => 'A budget carries the agency\'s own allocation thinking — what was held back, what '
                .'was moved and when. The figure a client is owed is what was spent on their behalf, which is on '
                .'the performance surface.',
            self::Tasks => 'A board carries the agency\'s own reasons for things being blocked and its own '
                .'estimates of how long work takes. Both are internal conversations, and a client reading one '
                .'ends up managing the agency\'s week.',
            self::Approvals => 'An approval trail records who authorised a change and who hesitated. It is the '
                .'record that makes internal accountability possible, and it only stays honest while it is '
                .'internal.',
            self::Leads => 'Leads are named people carrying the agency\'s own qualification notes. A client has '
                .'their own system of record for those people, and a half-attributed second list is how the same '
                .'lead gets counted twice in two places.',
            self::Attribution => 'Every attribution figure except the recorded one is a model, and a modelled '
                .'number read as a record is the most expensive mistake this product can cause. It needs the '
                .'coverage caveat explained by somebody who can answer a question about it.',
            self::Journeys => 'A journey is a reconstruction from identity evidence of varying confidence. '
                .'Presented without that evidence it reads as a recording of what a person did, which it is not.',
            self::Audit => 'The audit trail names agency staff and records every action they took. It is a '
                .'personnel record as much as a security one.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
