<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Fixture;

use App\Application\Integration\DTO\DiscoveredAccount;
use App\Domain\Integration\ExternalAccountStatus;
use App\Domain\Integration\Provider;

/**
 * Sandbox ad accounts, for exercising the binding model without a live provider.
 *
 * Every name carries the SANDBOX label, and the external ids are obviously
 * synthetic. There are no metrics here and there will not be: account structure
 * can be simulated harmlessly, but spend and performance figures cannot — a
 * fabricated number in front of a client is the one failure this project treats
 * as unrecoverable.
 */
final class SandboxAccounts
{
    /** @return list<DiscoveredAccount> */
    public static function for(Provider $provider): array
    {
        return match ($provider) {
            Provider::Meta => [
                new DiscoveredAccount('act_sandbox_1001', 'SANDBOX — Alhambra Retail (Meta)', 'SAR', 'Asia/Riyadh', ExternalAccountStatus::Active),
                new DiscoveredAccount('act_sandbox_1002', 'SANDBOX — Najd Fintech (Meta)', 'SAR', 'Asia/Riyadh', ExternalAccountStatus::Active),
                new DiscoveredAccount('act_sandbox_1003', 'SANDBOX — Dormant account (Meta)', 'SAR', 'Asia/Riyadh', ExternalAccountStatus::Disabled),
            ],
            Provider::GoogleAds => [
                new DiscoveredAccount('sandbox-100-200-3000', 'SANDBOX — Alhambra Retail (Google Ads)', 'SAR', 'Asia/Riyadh', ExternalAccountStatus::Active),
                new DiscoveredAccount('sandbox-100-200-3001', 'SANDBOX — Rawdah Hospitality (Google Ads)', 'SAR', 'Asia/Riyadh', ExternalAccountStatus::Unsettled),
            ],
            Provider::TikTok => [
                new DiscoveredAccount('sandbox_tt_7001', 'SANDBOX — Najd Fintech (TikTok)', 'SAR', 'Asia/Riyadh', ExternalAccountStatus::Active),
            ],
            Provider::Snapchat => [
                new DiscoveredAccount('sandbox-snap-5001', 'SANDBOX — Alhambra Retail (Snapchat)', 'SAR', 'Asia/Riyadh', ExternalAccountStatus::Active),
            ],
            Provider::X => [
                new DiscoveredAccount('sandbox_x_18ce', 'SANDBOX — Najd Fintech (X)', 'USD', 'Asia/Riyadh', ExternalAccountStatus::Active),
            ],
            Provider::LinkedIn => [
                new DiscoveredAccount('sandbox-li-900100', 'SANDBOX — Najd Fintech (LinkedIn)', 'USD', 'Asia/Riyadh', ExternalAccountStatus::Active),
            ],
            // No fixtures: we do not know what this programme's accounts look
            // like, so inventing a shape would be a guess the UI presents as fact.
            Provider::OpenAI => [],
        };
    }
}
