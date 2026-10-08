<?php

declare(strict_types=1);

namespace App\Application\Identity\Exception;

use App\Domain\Shared\Exception\DomainException;

/**
 * A password change this product refuses.
 *
 * Each message says what to do and **none of them says anything about the account**. "That is not your current
 * password" is the same sentence whoever is sitting at the machine, which is the point: a message that
 * distinguished a wrong password from a wrong account would be a way to learn which accounts exist.
 */
final class PasswordRejected extends DomainException
{
    public function errorCode(): string
    {
        return 'password_rejected';
    }

    public static function currentPasswordWrong(): self
    {
        return new self('That is not your current password. It is asked for because a session can be left open on a machine that is not yours.');
    }

    public static function tooShort(int $minimum): self
    {
        return new self(sprintf(
            'Use at least %d characters. A long passphrase is stronger than a short word with a symbol in it, '
            .'which is why there is no rule here about capitals or punctuation.',
            $minimum,
        ));
    }

    public static function unchanged(): self
    {
        return new self('That is the password you already have. Changing a password you think somebody knows has to end with a different one.');
    }
}
