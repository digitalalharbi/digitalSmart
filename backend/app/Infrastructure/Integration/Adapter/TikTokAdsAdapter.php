<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Domain\Integration\Provider;

/**
 * TikTok Ads.
 *
 * BLOCKED_EXTERNAL: needs a TikTok for Business developer app with Marketing
 * API approval.
 */
final class TikTokAdsAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::TikTok;
    }
}
