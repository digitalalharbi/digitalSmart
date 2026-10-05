<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Domain\Integration\Provider;

/**
 * Google Ads.
 *
 * BLOCKED_EXTERNAL: needs a Google Cloud OAuth client and a developer token
 * approved by Google for production use. The developer token is the long pole —
 * basic access is granted on application, production access on review.
 */
final class GoogleAdsAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::GoogleAds;
    }
}
