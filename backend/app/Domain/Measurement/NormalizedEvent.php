<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

/**
 * The event vocabulary digitalSmart reasons in.
 *
 * Every provider names the same things differently, and most clients rename them again:
 * one GA4 property calls a lead `generate_lead`, another calls it `lead_form_submit`, a
 * third fires `contact` and means a WhatsApp click. **Nothing is assumed from a raw event
 * name.** A raw name becomes one of these only because somebody mapped it, per project,
 * and that mapping is a record with an author.
 *
 * `Custom` is deliberately part of the vocabulary rather than a fallback: a client with a
 * meaningful event this list does not cover should be able to report on it honestly rather
 * than have it filed under the nearest-looking thing.
 */
enum NormalizedEvent: string
{
    case PageView = 'page_view';
    case SessionStart = 'session_start';
    case ViewItem = 'view_item';
    case AddToCart = 'add_to_cart';
    case BeginCheckout = 'begin_checkout';
    case Purchase = 'purchase';
    case GenerateLead = 'generate_lead';
    case FormStart = 'form_start';
    case FormSubmit = 'form_submit';
    case WhatsAppClick = 'whatsapp_click';
    case PhoneClick = 'phone_click';
    case Signup = 'sign_up';
    case AppInstall = 'app_install';
    case Custom = 'custom_event';

    public function label(): string
    {
        return match ($this) {
            self::PageView => 'Page view',
            self::SessionStart => 'Session start',
            self::ViewItem => 'Product view',
            self::AddToCart => 'Add to cart',
            self::BeginCheckout => 'Checkout started',
            self::Purchase => 'Purchase',
            self::GenerateLead => 'Lead',
            self::FormStart => 'Form started',
            self::FormSubmit => 'Form submitted',
            self::WhatsAppClick => 'WhatsApp click',
            self::PhoneClick => 'Phone click',
            self::Signup => 'Sign-up',
            self::AppInstall => 'App install',
            self::Custom => 'Custom event',
        };
    }

    /**
     * Whether this event is, by its nature, something somebody wanted to happen.
     *
     * A page view is not a conversion however a client labels it. This does not decide
     * whether a *definition* counts as one — that is a per-project decision in the
     * conversion registry — it decides what the interface offers as a sensible default,
     * so nobody has to argue with a form about whether `session_start` is a goal.
     */
    public function isConversionByNature(): bool
    {
        return match ($this) {
            self::PageView, self::SessionStart, self::ViewItem, self::FormStart => false,
            default => true,
        };
    }

    /**
     * Whether a count of this event may be compared with ad spend as a cost per action.
     *
     * A WhatsApp *click* is not a conversation and a form *start* is not a submission.
     * Reporting a cost per WhatsApp click as a cost per lead is the single most common way
     * an agency overstates its results, so the distinction lives in the domain rather than
     * in a chart's title.
     */
    public function isIntentSignalOnly(): bool
    {
        return match ($this) {
            self::WhatsAppClick, self::PhoneClick, self::FormStart => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
