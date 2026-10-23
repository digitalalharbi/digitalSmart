<?php

declare(strict_types=1);

namespace App\Application\Tracking\DTO;

/**
 * What a tracking link is bound to in the campaign hierarchy, after the hierarchy has been checked.
 *
 * Names are carried so a link can fill its UTM parameters from the campaign it advertises; the platform and account are
 * inherited from the campaign, never typed beside it.
 */
final readonly class CampaignBinding
{
    public function __construct(
        public string $platform,
        public string $externalAccountId,
        public string $campaignId,
        public string $campaignName,
        public ?string $adGroupId,
        public ?string $adGroupName,
        public ?string $adId,
        public ?string $adName,
        public ?string $creativeId,
        public ?string $creativeName,
    ) {}
}
