<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

/**
 * The one vocabulary of things that happen between an advertisement and a sale.
 *
 * Three families, kept apart because they arrive differently and are trusted differently:
 *
 * - **Browser events**, sent by a page the visitor is on. Anybody can send one, so none of them is evidence of
 *   anything beyond "this page said so".
 * - **Server events**, written by this product when it redirects a link or accepts a form. Those are facts about
 *   this product's own behaviour.
 * - **Pipeline events** — contacted, qualified, appointment, sale — are written by people working a lead. They are
 *   named here so a funnel can use one vocabulary, but they are *recorded in `lead_events`*, which already holds
 *   them; a second copy would be a second place for the two to disagree.
 */
enum TrackingEventName: string
{
    case LinkClick = 'link_click';
    case SessionStart = 'session_start';
    case LandingView = 'landing_view';
    case FormView = 'form_view';
    case FormStart = 'form_start';
    case FormStepCompleted = 'form_step_completed';
    case FormValidationError = 'form_validation_error';
    case FormSubmit = 'form_submit';
    case WhatsAppClick = 'whatsapp_click';
    case WhatsAppConversationStarted = 'whatsapp_conversation_started';
    case LeadCreated = 'lead_created';
    case LeadAssigned = 'lead_assigned';
    case LeadContactAttempt = 'lead_contact_attempt';
    case LeadContacted = 'lead_contacted';
    case LeadQualified = 'lead_qualified';
    case AppointmentBooked = 'appointment_booked';
    case OpportunityCreated = 'opportunity_created';
    case SaleCompleted = 'sale_completed';
    case LeadLost = 'lead_lost';

    /** Events a visitor's page may report. Everything else is written by this product, never accepted from a browser. */
    public function isBrowserReportable(): bool
    {
        return in_array($this, [
            self::LandingView,
            self::FormView,
            self::FormStart,
            self::FormStepCompleted,
            self::FormValidationError,
            self::WhatsAppClick,
        ], true);
    }

    /** Events that count as a conversion for dispatch and de-duplication purposes. */
    public function isConversionLike(): bool
    {
        return in_array($this, [
            self::FormSubmit,
            self::LeadCreated,
            self::LeadQualified,
            self::AppointmentBooked,
            self::OpportunityCreated,
            self::SaleCompleted,
        ], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** @return list<string> */
    public static function browserValues(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            array_values(array_filter(self::cases(), static fn (self $case): bool => $case->isBrowserReportable())),
        );
    }
}
