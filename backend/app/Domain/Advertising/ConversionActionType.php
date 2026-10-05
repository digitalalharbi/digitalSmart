<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

/**
 * Normalised conversion actions.
 *
 * Meta returns an `actions` array keyed by `action_type` strings such as
 * `purchase`, `lead`, `add_to_cart`, `omni_purchase`; Google exposes conversion
 * actions by category; TikTok and Snapchat each have their own vocabulary. This
 * is the common set the product reports on, with the provider's own string kept
 * beside it so nothing is lost in translation.
 */
enum ConversionActionType: string
{
    case Purchase = 'purchase';
    case Lead = 'lead';
    case AddToCart = 'add_to_cart';
    case InitiateCheckout = 'initiate_checkout';
    case CompleteRegistration = 'complete_registration';
    case Contact = 'contact';
    case Subscribe = 'subscribe';
    case AppInstall = 'app_install';
    case LandingPageView = 'landing_page_view';
    case ViewContent = 'view_content';
    case Search = 'search';
    case Custom = 'custom';
    case Other = 'other';

    /** Whether a monetary value is normally attached. */
    public function carriesValue(): bool
    {
        return in_array($this, [self::Purchase, self::InitiateCheckout, self::Subscribe, self::Custom], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::Lead => 'Lead',
            self::AddToCart => 'Add to cart',
            self::InitiateCheckout => 'Checkout started',
            self::CompleteRegistration => 'Registration',
            self::Contact => 'Contact',
            self::Subscribe => 'Subscription',
            self::AppInstall => 'App install',
            self::LandingPageView => 'Landing page view',
            self::ViewContent => 'Content view',
            self::Search => 'Search',
            self::Custom => 'Custom conversion',
            self::Other => 'Other',
        };
    }
}
