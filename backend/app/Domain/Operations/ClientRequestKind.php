<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/** What a client is asking for. */
enum ClientRequestKind: string
{
    case NewCampaign = 'new_campaign';

    case Change = 'change';

    case Creative = 'creative';

    case Report = 'report';

    /** A question rather than a request for work. Kept distinct because it needs an answer, not a task. */
    case Question = 'question';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NewCampaign => 'A new campaign',
            self::Change => 'A change to something running',
            self::Creative => 'Creative work',
            self::Report => 'A report or a figure',
            self::Question => 'A question',
            self::Other => 'Something else',
        };
    }

    /**
     * Whether accepting this normally produces work somebody has to do.
     *
     * False for a question, which needs an answer. A queue that spawned a task for every question would fill a
     * board with cards whose completion is a sentence in a reply.
     */
    public function producesWork(): bool
    {
        return $this !== self::Question;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
