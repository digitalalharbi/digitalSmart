<?php

declare(strict_types=1);

namespace App\Interface\Http\Support;

use App\Interface\Http\Middleware\BindActorContext;
use Symfony\Component\HttpFoundation\Cookie;

/** The active-workspace cookie, written by sign-in and a switch only. See `BindActorContext::COOKIE`. */
final class WorkspaceCookie
{
    public static function make(string $workspaceId): Cookie
    {
        return cookie(
            name: BindActorContext::COOKIE,
            value: $workspaceId,
            minutes: (int) config('session.lifetime', 120),
            path: '/',
            domain: config('session.domain'),
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            sameSite: (string) config('session.same_site', 'lax'),
        );
    }

    public static function forget(): Cookie
    {
        return cookie()->forget(BindActorContext::COOKIE, '/', config('session.domain'));
    }
}
