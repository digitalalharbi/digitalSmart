<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Funnel;

use App\Domain\Tracking\TrackingEventName;

/**
 * Where a funnel stage's count comes from. A closed list: a stage is one of these, never a query somebody wrote.
 *
 * Each source also says **what it counts** — a visit (session), a submission or a lead — because a funnel moves from
 * visits to people and a conversion rate between two different things is only meaningful when the move is stated.
 */
enum FunnelSource: string
{
    case LinkClick = 'link_click';
    case LandingView = 'landing_view';
    case FormStart = 'form_start';
    case FormSubmit = 'form_submit';
    case LeadCreated = 'lead_created';
    case LeadContacted = 'lead_contacted';
    case LeadQualified = 'lead_qualified';
    case AppointmentBooked = 'appointment_booked';
    case OpportunityCreated = 'opportunity_created';
    case SaleCompleted = 'sale_completed';

    /** @return 'session'|'submission'|'lead' */
    public function unit(): string
    {
        return match ($this) {
            self::LinkClick, self::LandingView, self::FormStart => 'session',
            self::FormSubmit => 'submission',
            default => 'lead',
        };
    }

    /** Whether a destination page reports it rather than this product recording it. */
    public function isReportedByPage(): bool
    {
        return $this === self::LandingView || $this === self::FormStart;
    }

    public function event(): TrackingEventName
    {
        return TrackingEventName::from($this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The standard lead funnel, used for a project that has not defined its own.
     *
     * @return list<self>
     */
    public static function defaultLeadFunnel(): array
    {
        return [
            self::LinkClick, self::LandingView, self::FormStart, self::FormSubmit, self::LeadCreated,
            self::LeadContacted, self::LeadQualified, self::AppointmentBooked, self::SaleCompleted,
        ];
    }
}
