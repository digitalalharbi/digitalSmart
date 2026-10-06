<?php

declare(strict_types=1);

namespace App\Domain\Journeys;

/**
 * What kind of thing happened.
 *
 * **Short on purpose, and the omissions are the point.** There is no `page_view`, no `session` and no
 * `ad_click` here, because this product does not have them: GA4's Data API returns aggregated reports rather
 * than per-user event streams, and the documented route to event-level data is a BigQuery export this product
 * does not read.
 *
 * So what can be assembled is a record of a person's **conversions** — their enquiries and their orders — and
 * not the marketing touches that led to them. `docs/JOURNEYS.md` says so plainly rather than drawing a
 * three-step funnel from a single data point and calling it a journey.
 */
enum TouchpointKind: string
{
    /** An enquiry: a form submitted, a message received, a call answered. */
    case Lead = 'lead';

    /** An order placed. */
    case Order = 'order';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Enquiry',
            self::Order => 'Order',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
