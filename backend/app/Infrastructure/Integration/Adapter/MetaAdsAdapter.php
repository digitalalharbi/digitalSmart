<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Domain\Integration\Provider;

/**
 * Meta Ads (Facebook and Instagram).
 *
 * BLOCKED_EXTERNAL: needs a Meta app of type Business with Marketing API
 * access, `ads_read`/`ads_management` approved through App Review, and the
 * redirect URI allow-listed. See docs/INTEGRATIONS.md.
 */
final class MetaAdsAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::Meta;
    }
}
