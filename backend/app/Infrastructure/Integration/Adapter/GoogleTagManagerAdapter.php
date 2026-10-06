<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Domain\Integration\Provider;

/**
 * Google Tag Manager.
 *
 * Inspection only, and deliberately so. The API can create, modify and publish tags;
 * this product reads containers to answer "is the tracking actually in place" and will
 * not write. A dashboard that silently republished somebody's container is how
 * measurement breaks on a Friday afternoon.
 *
 * `https://www.googleapis.com/tagmanager/v2/`, hierarchy accounts → containers →
 * workspaces → tags, triggers and variables, plus container versions. The published
 * quota is tight and shared: 0.25 queries per second on a 100-second sliding window and
 * 10,000 requests a day, both **per Google Cloud project**, which means every workspace
 * on one deployment draws from the same allowance. Exceeding it answers 403 rather than
 * 429 — worth knowing, because the usual retry heuristics look for 429.
 *
 * BLOCKED_EXTERNAL: needs a Google Cloud project with the Tag Manager API enabled and a
 * user with at least read access to the container.
 */
final class GoogleTagManagerAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::GoogleTagManager;
    }
}
