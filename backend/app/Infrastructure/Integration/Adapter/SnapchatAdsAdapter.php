<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Domain\Integration\Provider;

/**
 * Snapchat Ads.
 *
 * BLOCKED_EXTERNAL: needs a Snap Business app with Marketing API access.
 */
final class SnapchatAdsAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::Snapchat;
    }
}
