<?php

declare(strict_types=1);

namespace App\Interface\Http\Middleware;

use Illuminate\Contracts\Auth\Guard;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * A session is only as current as the password it was opened with.
 *
 * The framework's `AuthenticateSession` stores the password hash in the session and compares it on every
 * request, so a session opened with a password that has since been changed fails its next request instead of
 * carrying on. That is what makes a password change mean something: somebody changes theirs *because* they think
 * it is known, and leaving the other sessions signed in would achieve nothing.
 *
 * **Why this subclass exists.** `auth:sanctum` makes `sanctum` the default guard for the rest of the request, so
 * the parent asks a `RequestGuard` for `viaRemember()` and gets a `BadMethodCallException` — a 500 on every
 * authenticated read. Found immediately by the existing authentication tests, which is the argument for having
 * them. This product's sessions are web sessions that Sanctum reads statefully, so the guard named here is the
 * one that owns them.
 *
 * The session key is unchanged: the parent builds it from the default driver on both the write and the read, so
 * the two agree whatever the driver happens to be called.
 */
final class AuthenticateSessionForSpa extends AuthenticateSession
{
    protected function guard(): Guard
    {
        return $this->auth->guard('web');
    }
}
