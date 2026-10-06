<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Adapter;

use App\Domain\Integration\Provider;

/**
 * Zid.
 *
 * OAuth 2.0 against `https://oauth.zid.sa/oauth/authorize` and `/oauth/token`, with the
 * API at `https://api.zid.sa/v1`. Its authentication is the unusual part: **two headers
 * carry two different tokens.** `Authorization: Bearer <authorization>` grants access to
 * the API at all, while `X-Manager-Token` carries the `access_token` and scopes the
 * request to one store. They are not interchangeable, and sending the same value in both
 * — the obvious mistake — fails in a way that reads like a permissions problem.
 *
 * Both the manager token and the refresh token last a year, so rotation is a scheduled
 * concern rather than a per-request one; Zid recommends refreshing at around ten months.
 *
 * BLOCKED_EXTERNAL: needs an app registered in Zid's partner dashboard and a merchant who
 * installs it.
 */
final class ZidAdapter extends AbstractProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::Zid;
    }
}
