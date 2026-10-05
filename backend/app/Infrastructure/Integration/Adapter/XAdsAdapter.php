<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Domain\Integration\Provider;

/**
 * X Ads.
 *
 * BLOCKED_EXTERNAL: needs a developer account with Ads API access, which is
 * approved separately from general API access and is usually paid. X also uses
 * OAuth 1.0a rather than OAuth 2.0, so its authorisation flow differs from the
 * others — handled by the adapter, invisible to the rest of the platform.
 */
final class XAdsAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::X;
    }
}
