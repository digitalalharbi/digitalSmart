<?php

declare(strict_types=1);

namespace App\Domain\Portal;

/**
 * A surface an agency may expose to a client.
 *
 * This enum is a **closed list of what can be disclosed**, and that is its whole job. A portal built the other way
 * round — every surface available, each behind a toggle — makes exposing the agency's own reasoning a configuration
 * mistake rather than an impossibility, and configuration mistakes are made on a Friday by whoever is covering.
 *
 * Membership here is a judgement about *whose information a surface carries*, not about how sensitive it feels:
 *
 *  - **It is the client's own information**, or a statement about their own property. Performance against their
 *    spend, revenue from their store, whether their tracking is working, the thread they themselves wrote in.
 *  - **It has a client-facing form that already exists**, enforced in SQL rather than by a filter. Reports have an
 *    audience; client requests exclude internal messages from the query itself. A surface that would need a new
 *    "hide the internal parts" pass to be safe is not ready to be in this enum, because that pass is one careless
 *    refactor from being skipped.
 *
 * What is deliberately absent is in {@see WithheldModule}, with the reason for each. Absent *and explained*: a
 * client asking "why can't I see the budget" gets an answer, and an account manager asking the same question gets
 * the same one.
 *
 * And one surface is absent for the opposite reason. The client's own intake thread is in {@see AlwaysAvailable}:
 * it is correspondence the client wrote half of, a person is entitled to read a conversation they are part of, and
 * a switch that pretended to control it would be a switch that controls nothing — or worse, one an agency could
 * use to withhold the answer to a question the client themselves asked.
 */
enum PortalModule: string
{
    /** Advertising performance for the client's own projects. */
    case Performance = 'performance';

    /** Reports the agency marked client-safe for this client. Never an internal one. */
    case Reports = 'reports';

    /** Store revenue, which is the client's own commerce data. */
    case StoreRevenue = 'store_revenue';

    /** Whether measurement on the client's own property is working. */
    case Tracking = 'tracking';

    public function label(): string
    {
        return match ($this) {
            self::Performance => 'Performance',
            self::Reports => 'Reports',
            self::StoreRevenue => 'Store revenue',
            self::Tracking => 'Tracking',
        };
    }

    /** What the client will actually be able to read, said in the words they would read it in. */
    public function description(): string
    {
        return match ($this) {
            self::Performance => 'What the advertising did: spend, reach into the funnel, and the figures that '
                .'follow from them, for their own projects only.',
            self::Reports => 'The reports marked safe to send to this client. Internal reports never appear here, '
                .'whatever this setting says.',
            self::StoreRevenue => 'Orders and revenue from their own store, with the revenue source named on every '
                .'figure.',
            self::Tracking => 'Whether measurement on their own site is working, and what is wrong with it.',
        };
    }

    /**
     * What has to exist in the workspace before this module can show anything.
     *
     * Null means the module works from data the client already has by existing. The others can be switched on
     * against nothing, and an empty surface exposed to a client reads as "the agency has done nothing" rather than
     * as "this is not connected yet" — so the agency is told before they turn it on, not after the client asks.
     */
    public function prerequisite(): ?string
    {
        return match ($this) {
            self::Performance => 'a project bound to an advertising account',
            self::StoreRevenue => 'a connected store',
            self::Tracking => 'a measurement source',
            /* Nothing: a report is a document the agency already wrote for this client. */
            self::Reports => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
