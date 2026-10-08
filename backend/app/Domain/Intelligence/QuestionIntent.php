<?php

declare(strict_types=1);

namespace App\Domain\Intelligence;

/**
 * The questions this product recognises when somebody types one.
 *
 * A closed list, matched by keyword, and that is a deliberate choice rather than a limitation to be apologised
 * for. The alternative is to hand a question to a language model and let it decide which figures to fetch — at
 * which point the model is choosing the denominator, and a wrong denominator is the one error in analytics that
 * nobody catches because the answer looks fine.
 *
 * So the words a reader types select an intent, the intent selects the query, and **the question is refused when
 * no intent matches**. A refusal naming the questions that *are* understood is more useful than a confident answer
 * to a different question — and far more useful than a plausible one to the right question.
 */
enum QuestionIntent: string
{
    case Spend = 'spend';

    case Efficiency = 'efficiency';

    case Delivery = 'delivery';

    case Conversions = 'conversions';

    case Change = 'change';

    case Tracking = 'tracking';

    public function label(): string
    {
        return match ($this) {
            self::Spend => 'What was spent',
            self::Efficiency => 'What it cost per click or per conversion',
            self::Delivery => 'How much was delivered',
            self::Conversions => 'How many conversions were recorded',
            self::Change => 'What changed against the period before',
            self::Tracking => 'Whether measurement is working',
        };
    }

    /** An example in the words somebody would actually type, shown on the surface so the list is usable. */
    public function example(): string
    {
        return match ($this) {
            self::Spend => 'How much did we spend last month?',
            self::Efficiency => 'What is our cost per click?',
            self::Delivery => 'How many impressions did we get?',
            self::Conversions => 'How many conversions were there?',
            self::Change => 'What changed since last month?',
            self::Tracking => 'Is our tracking working?',
        };
    }

    /**
     * The words that select this intent.
     *
     * Matched as substrings against a lower-cased question, which is crude and is **meant** to be: a reader can
     * see the whole matching rule, and a question that matches nothing is told so. Arabic terms sit beside the
     * English ones because the interface is bilingual and a reader asking in Arabic is not asking a lesser
     * question.
     *
     * @return list<string>
     */
    public function keywords(): array
    {
        return match ($this) {
            self::Spend => ['spend', 'spent', 'budget', 'cost in total', 'how much did', 'صرف', 'أنفق', 'ميزانية'],
            self::Efficiency => ['cpc', 'cpa', 'cpm', 'cost per', 'efficiency', 'expensive', 'تكلفة', 'كفاءة'],
            self::Delivery => ['impression', 'reach', 'delivery', 'views', 'clicks', 'ظهور', 'نقرات', 'وصول'],
            self::Conversions => ['conversion', 'purchase', 'lead', 'sale', 'تحويل', 'شراء', 'عميل محتمل'],
            self::Change => ['change', 'changed', 'why', 'drop', 'fell', 'rose', 'increase', 'decrease', 'تغير', 'انخفاض', 'ارتفاع', 'لماذا'],
            self::Tracking => ['tracking', 'tag', 'event', 'measurement', 'pixel', 'تتبع', 'قياس', 'حدث'],
        };
    }

    /**
     * The first intent whose keywords appear in this question, or null.
     *
     * First rather than best: ordering is the enum's own, and a question matching two intents gets the earlier
     * one. A scoring function would make the mapping unreadable and the refusal unexplainable, and the honest
     * answer to an ambiguous question is to answer one reading and name the other — which the surface does.
     */
    public static function match(string $question): ?self
    {
        $needle = mb_strtolower(trim($question));

        if ($needle === '') {
            return null;
        }

        foreach (self::cases() as $intent) {
            foreach ($intent->keywords() as $keyword) {
                if (str_contains($needle, $keyword)) {
                    return $intent;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
