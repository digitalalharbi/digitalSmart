<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * Ad platforms digitalSmart integrates with.
 *
 * The capability sets below describe what each platform's **API** supports, as
 * published by the provider. They are not promises about what is wired up: a
 * capability is only exercised once the adapter implements it and a live
 * connection exists. Nothing here may be widened to make a UI control appear.
 */
enum Provider: string
{
    case Meta = 'meta';
    case GoogleAds = 'google_ads';
    case TikTok = 'tiktok';
    case Snapchat = 'snapchat';
    case X = 'x';
    case LinkedIn = 'linkedin';
    case OpenAI = 'openai';

    public function label(): string
    {
        return match ($this) {
            self::Meta => 'Meta Ads',
            self::GoogleAds => 'Google Ads',
            self::TikTok => 'TikTok Ads',
            self::Snapchat => 'Snapchat Ads',
            self::X => 'X Ads',
            self::LinkedIn => 'LinkedIn Ads',
            self::OpenAI => 'ChatGPT Ads',
        };
    }

    /** How a workspace authorises this provider. */
    public function authMethod(): AuthMethod
    {
        return match ($this) {
            self::Meta, self::GoogleAds, self::TikTok,
            self::Snapchat, self::LinkedIn => AuthMethod::OAuth2,
            self::X => AuthMethod::OAuth1,
            // Unknown until the programme grants access; see docs/INTEGRATIONS.md.
            self::OpenAI => AuthMethod::Unknown,
        };
    }

    /**
     * What this provider's API can do.
     *
     * @return list<ProviderCapability>
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::Meta, self::GoogleAds, self::TikTok, self::Snapchat, self::LinkedIn => [
                ProviderCapability::AccountDiscovery,
                ProviderCapability::CampaignDiscovery,
                ProviderCapability::MetricsSync,
                ProviderCapability::CreativeRequirements,
                ProviderCapability::CampaignStatusMutation,
                ProviderCapability::BudgetMutation,
            ],

            self::X => [
                ProviderCapability::AccountDiscovery,
                ProviderCapability::CampaignDiscovery,
                ProviderCapability::MetricsSync,
                ProviderCapability::CreativeRequirements,
                ProviderCapability::CampaignStatusMutation,
                ProviderCapability::BudgetMutation,
            ],

            /*
             * Deliberately empty. The programme is not generally available and
             * its contract is unknown, so declaring anything here would be a
             * guess that the UI would then present as fact.
             */
            self::OpenAI => [],
        };
    }

    public function supports(ProviderCapability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * Credential keys the adapter needs before it can attempt a live handshake.
     *
     * @return list<string>
     */
    public function requiredCredentialKeys(): array
    {
        return match ($this) {
            self::Meta => ['client_id', 'client_secret'],
            self::GoogleAds => ['client_id', 'client_secret', 'developer_token'],
            self::TikTok => ['app_id', 'app_secret'],
            self::Snapchat => ['client_id', 'client_secret'],
            self::X => ['consumer_key', 'consumer_secret'],
            self::LinkedIn => ['client_id', 'client_secret'],
            self::OpenAI => ['api_key'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
