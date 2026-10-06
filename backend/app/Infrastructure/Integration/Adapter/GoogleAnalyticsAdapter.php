<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Domain\Integration\Provider;

/**
 * Google Analytics 4.
 *
 * Read-only by design. The Admin API can write, and this product never will: changing
 * somebody's analytics configuration from a reporting tool is not a feature, it is a way
 * to break a measurement setup nobody can then explain.
 *
 * Two APIs, one OAuth client. The Admin API
 * (`https://analyticsadmin.googleapis.com/v1beta/`) lists accounts, properties and data
 * streams; the Data API answers reporting questions. Read access needs the
 * `https://www.googleapis.com/auth/analytics.readonly` scope.
 *
 * BLOCKED_EXTERNAL: needs a Google Cloud project with both APIs enabled, an OAuth client
 * whose redirect URI is allow-listed, and a user who can read the property. Unlike the
 * advertising platforms there is no programme to be admitted to — the blocker is
 * configuration and consent, not approval, which is why `apiStatus()` says generally
 * available.
 */
final class GoogleAnalyticsAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::GoogleAnalytics;
    }
}
