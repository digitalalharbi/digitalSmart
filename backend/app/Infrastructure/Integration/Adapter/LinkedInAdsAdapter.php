<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Domain\Integration\Provider;

/**
 * LinkedIn Ads.
 *
 * BLOCKED_EXTERNAL: needs a LinkedIn app with Advertising API access, granted
 * through their partner programme.
 */
final class LinkedInAdsAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::LinkedIn;
    }
}
