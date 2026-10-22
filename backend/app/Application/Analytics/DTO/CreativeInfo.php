<?php

declare(strict_types=1);

namespace App\Application\Analytics\DTO;

use App\Domain\Advertising\CreativeMedia;

/**
 * What is known about a creative — or about an ad, at the grain of an ad — apart from how it performed.
 *
 * Nothing here is a provider's own identifier: the creative's key is this product's, the provider is a platform name, and
 * the preview is already judged by {@see CreativeMedia}. Provider specifics stay behind the mappers that filled the table.
 */
final readonly class CreativeInfo
{
    /**
     * @param  list<string>  $campaigns  up to a few campaign names the creative runs in
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $provider,
        public string $format,
        public CreativeMedia $media,
        public int $adCount,
        public array $campaigns,
        public int $campaignCount,
        /** The ad's own name and status, at the grain of an ad. Null for an asset, which is not one ad. */
        public ?string $adName = null,
        public ?string $adStatus = null,
        /** The asset an ad runs, so an ad row can point at the asset row. Null when the ad has no known creative. */
        public ?string $assetKey = null,
        /** Where the ad sits, at the grain of an ad: its ad group and its campaign, by this product's own keys. */
        public ?string $adGroupId = null,
        public ?string $adGroupName = null,
        public ?string $campaignId = null,
    ) {}
}
