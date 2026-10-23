<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Dispatch;

use App\Domain\Integration\Provider;

/**
 * Each provider's conversion path, as that provider's own documentation states it, and nothing more.
 *
 * Read on 2026-10-23. Anything not read is `UNVERIFIED` and cannot be mapped; an event name is never invented to make a
 * mapping look possible. The caveats are part of the entry because they decide whether a mapping is worth making.
 */
final class ProviderConversionCatalogue
{
    public const string READ_ON = '2026-10-23';

    /**
     * @return array{support: ConversionSupport, mechanism: ?string, events: ?list<string>, event_rule: ?string, dedup: ?string, needs_click_id: list<string>, source: ?string, caveat: ?string}
     */
    public static function for(Provider $provider): array
    {
        return match ($provider) {
            Provider::Meta => [
                'support' => ConversionSupport::Documented,
                'mechanism' => 'Conversions API (server events)',
                'events' => null,
                'event_rule' => 'A standard or custom event name; the name is not validated here because the standard list was not read.',
                'dedup' => 'Browser event and server event match on event name and event_id; matches within 48 hours keep the first.',
                'needs_click_id' => [],
                'source' => 'https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event',
                'caveat' => 'Match quality depends on customer identifiers this product does not send.',
            ],
            Provider::Snapchat => [
                'support' => ConversionSupport::Documented,
                'mechanism' => 'Conversions API (server events)',
                'events' => ['PURCHASE', 'SAVE', 'START_CHECKOUT', 'ADD_CART', 'VIEW_CONTENT', 'ADD_BILLING', 'SIGN_UP', 'SEARCH', 'PAGE_VIEW', 'SUBSCRIBE', 'AD_CLICK', 'AD_VIEW', 'RESERVE', 'ADD_TO_WISHLIST', 'START_TRIAL', 'CUSTOM_EVENT_1', 'CUSTOM_EVENT_2', 'CUSTOM_EVENT_3', 'CUSTOM_EVENT_4', 'CUSTOM_EVENT_5'],
                'event_rule' => 'One of the documented event names. There is no documented lead event; use SIGN_UP or a CUSTOM_EVENT.',
                'dedup' => 'event_id and event_name deduplicate against the web pixel; the pixel’s client_dedup_id must equal event_id.',
                'needs_click_id' => [],
                'source' => 'https://developers.snap.com/api/marketing-api/Conversions-API/Parameters',
                'caveat' => null,
            ],
            Provider::GoogleAds => [
                'support' => ConversionSupport::Documented,
                'mechanism' => 'Offline click conversions (ConversionUploadService.UploadClickConversions)',
                'events' => null,
                'event_rule' => 'A conversion action resource name of the form customers/{id}/conversionActions/{id}, of type UPLOAD_CLICKS.',
                'dedup' => 'order_id, unique per conversion action, prevents duplicates.',
                'needs_click_id' => ['gclid', 'gbraid', 'wbraid'],
                'source' => 'https://developers.google.com/google-ads/api/docs/conversions/upload-clicks',
                'caveat' => 'Google states that from 2026-06-15 this method fails for a developer token that has not already used it, and directs new integrations to the Data Manager API, which this product has not read. A new token may be refused.',
            ],
            Provider::TikTok, Provider::LinkedIn => [
                'support' => ConversionSupport::Unverified,
                'mechanism' => null,
                'events' => null,
                'event_rule' => null,
                'dedup' => null,
                'needs_click_id' => [],
                'source' => null,
                'caveat' => sprintf('%s has an events API, but its documentation could not be read in a machine-readable form. Nothing is mapped until it is.', $provider->label()),
            ],
            default => [
                'support' => ConversionSupport::Unsupported,
                'mechanism' => null,
                'events' => null,
                'event_rule' => null,
                'dedup' => null,
                'needs_click_id' => [],
                'source' => null,
                'caveat' => sprintf('No server conversion path for %s that digitalSmart implements.', $provider->label()),
            ],
        };
    }

    /** @return list<Provider> the providers a mapping can be offered for, in the owner's order */
    public static function listed(): array
    {
        return [Provider::Snapchat, Provider::TikTok, Provider::Meta, Provider::GoogleAds, Provider::X, Provider::LinkedIn, Provider::OpenAI];
    }

    /** Whether a provider event name is acceptable for a mapping; null when it is, a sentence when it is not. */
    public static function refusal(Provider $provider, string $name): ?string
    {
        $entry = self::for($provider);

        if (! $entry['support']->allowsMapping()) {
            return $entry['caveat'] ?? 'This provider cannot be mapped.';
        }

        return match ($provider) {
            Provider::Snapchat => in_array($name, $entry['events'] ?? [], true) ? null : 'Snapchat documents these event names: '.implode(', ', $entry['events'] ?? []).'.',
            Provider::GoogleAds => preg_match('#^customers/\d+/conversionActions/\d+$#', $name) === 1 ? null : 'A Google conversion action looks like customers/1234567890/conversionActions/987654321.',
            Provider::Meta => preg_match('/^[A-Za-z][A-Za-z0-9_]{1,39}$/', $name) === 1 ? null : 'A Meta event name is letters, numbers and underscores.',
            default => 'This provider cannot be mapped.',
        };
    }
}
