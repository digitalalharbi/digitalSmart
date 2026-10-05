<?php

declare(strict_types=1);

namespace App\Application\Integration\Contract;

use App\Domain\Integration\Credentials;

/**
 * Encrypts provider credentials at rest.
 *
 * A port rather than a direct call to the framework's encrypter, so the domain
 * and use cases never depend on how encryption is done, and so key rotation is
 * a change in one adapter.
 */
interface CredentialCipher
{
    public function encrypt(Credentials $credentials): string;

    /** Returns empty credentials if the payload cannot be decrypted. */
    public function decrypt(?string $payload): Credentials;
}
