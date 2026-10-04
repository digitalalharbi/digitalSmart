<?php

declare(strict_types=1);

namespace App\Application\Identity\Exception;

use RuntimeException;

/**
 * Credentials were rejected.
 *
 * The message is deliberately identical whether the e-mail is unknown or the
 * password is wrong: distinguishing them turns the login form into an account
 * enumeration oracle. A deactivated account is the one exception — the person
 * needs to know to contact an administrator rather than retry forever.
 */
final class AuthenticationFailed extends RuntimeException
{
    public static function invalidCredentials(): self
    {
        return new self('These credentials do not match our records.');
    }

    public static function accountDeactivated(): self
    {
        return new self('This account has been deactivated. Contact your workspace administrator.');
    }

    public static function noWorkspace(): self
    {
        return new self('This account is not a member of any workspace yet.');
    }
}
