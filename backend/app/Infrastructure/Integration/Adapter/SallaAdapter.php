<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Domain\Integration\Provider;

/**
 * Salla.
 *
 * OAuth 2.0 against `https://accounts.salla.sa/oauth2/auth` and `/oauth2/token`, with the
 * Merchant API at `https://api.salla.dev/admin/v2/`. Two things about its tokens shape
 * the connection model:
 *
 *  - A refresh token is only issued when `offline_access` is among the requested scopes.
 *    Without it the connection dies after two weeks with no way back except re-consent.
 *  - **Refresh tokens are single-use.** Salla documents that reusing one — which two
 *    concurrent jobs would do — can invalidate the authorisation entirely, so refreshing
 *    has to be serialised per connection rather than attempted opportunistically.
 *
 * Access tokens last two weeks and refresh tokens a month.
 *
 * BLOCKED_EXTERNAL: needs an app registered in the Salla Partners Portal with a client id
 * and secret, and a merchant who installs it. Self-service, so the blocker is a business
 * registration rather than a programme admission.
 */
final class SallaAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::Salla;
    }
}
