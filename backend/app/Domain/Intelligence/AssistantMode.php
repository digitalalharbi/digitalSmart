<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

use App\Domain\Access\Permission;

/**
 * What the assistant is being asked to do.
 *
 * Four modes, and the division is not cosmetic: each answers a different question, needs different evidence, and
 * carries a different risk of being wrong in a way nobody notices. What is deliberately **not** here is in
 * {@see DeferredMode}, with the reason — an assistant offering a tab that produces nothing would be a placeholder
 * wearing a feature's clothes.
 *
 * The rule that governs all of them is one sentence: **this product computes every number, and the model only
 * writes the prose.** An assistant that let a language model produce a figure would be an assistant whose output
 * cannot be checked — and the figures here are a client's money. See {@see \App\Application\Intelligence\Service\NarrativeGuard},
 * which refuses a narrative containing a number the evidence does not contain.
 */
enum AssistantMode: string
{
    /** A question in words, answered from one of the intents this product recognises — or refused. */
    case Ask = 'ask';

    /** Everything the analysers can say about a client and a period, ordered by how much it matters. */
    case Analyse = 'analyse';

    /** One finding, taken apart: the figures behind it, the period either side, and what would confirm it. */
    case Investigate = 'investigate';

    /** A report definition proposed from what the analysis found. Saved by a person, never by the assistant. */
    case Report = 'report';

    public function label(): string
    {
        return match ($this) {
            self::Ask => 'Ask',
            self::Analyse => 'Analyse',
            self::Investigate => 'Investigate',
            self::Report => 'Report',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Ask => 'A question in plain words about a client\'s performance. It answers the questions it '
                .'recognises and says so when it does not recognise one, rather than guessing at what was meant.',
            self::Analyse => 'What the analysers can say about a period, each finding carrying the figures it '
                .'rests on.',
            self::Investigate => 'One finding in detail: the figures either side of it, and what would settle '
                .'whether it is real.',
            self::Report => 'A report definition built from what was found. It is proposed, not saved — the '
                .'reporting module is where a person decides.',
        };
    }

    /**
     * The permission a reader must hold.
     *
     * Analysis reads a client's performance, so it is gated on the permission the analytics surface already uses
     * rather than on a new one — an assistant that could answer questions a reader may not ask directly would be
     * a way around every other gate in this product. The two proposing modes need the permission to do something
     * with what they produce, because a proposal nobody may act on is a waste of a reader's attention.
     */
    public function requires(): Permission
    {
        return match ($this) {
            self::Ask, self::Analyse, self::Investigate => Permission::AnalyticsView,
            /*
             * Reporting's own permission, because the output is a report definition somebody is meant to save.
             * A proposal nobody may act on is a waste of a reader's attention.
             */
            self::Report => Permission::ReportsManage,
        };
    }

    /**
     * Whether this mode produces something that changes anything.
     *
     * False for every one of them, and that is the point. The assistant proposes; a person decides, in the module
     * that owns the decision. Nothing here writes a report, a budget, or a single byte to an advertising
     * platform — the same argument the approvals engine makes about why approval is not execution.
     */
    public function enactsAnything(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
