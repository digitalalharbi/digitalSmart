<?php

declare(strict_types=1);

namespace App\Domain\Dashboard\Exception;

use App\Domain\Shared\Exception\DomainException;

/**
 * Someone already has a dashboard or a saved view by this name.
 *
 * Names are unique per owner, enforced by a unique index. Without this check the
 * index is still obeyed, but it is reached as a database error — which arrives as a
 * 500 carrying the constraint name and the SQL, for what is an everyday mistake
 * someone makes while naming their second weekly review. The rule lives here so the
 * answer is a 422 that says which name to change.
 */
final class NameAlreadyUsed extends DomainException
{
    public function errorCode(): string
    {
        return 'name_already_used';
    }

    public static function byADashboard(string $name): self
    {
        return new self(sprintf('You already have a dashboard called "%s". Choose another name.', $name));
    }

    public static function byASavedView(string $name): self
    {
        return new self(sprintf('You already have a saved view called "%s". Choose another name.', $name));
    }
}
