<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration;

use App\Application\Integration\Contract\CredentialCipher;
use App\Domain\Integration\Credentials;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;

/**
 * AES-256-GCM encryption via the application key.
 *
 * Rotating APP_KEY therefore requires re-encrypting stored credentials; that is
 * recorded in docs/SECURITY.md rather than discovered during an incident.
 */
final readonly class LaravelCredentialCipher implements CredentialCipher
{
    public function __construct(private Encrypter $encrypter) {}

    public function encrypt(Credentials $credentials): string
    {
        return $this->encrypter->encrypt($credentials->toArray());
    }

    public function decrypt(?string $payload): Credentials
    {
        if ($payload === null || $payload === '') {
            return Credentials::empty();
        }

        try {
            $decrypted = $this->encrypter->decrypt($payload);
        } catch (DecryptException) {
            /*
             * Undecryptable usually means the key changed. Treat it as absent
             * rather than fatal: the connection reports "not configured" and
             * someone re-enters the credentials, instead of every page that
             * touches integrations breaking. The payload itself is never
             * logged.
             */
            Log::warning('A provider credential payload could not be decrypted; treating it as absent.');

            return Credentials::empty();
        }

        if (! is_array($decrypted)) {
            return Credentials::empty();
        }

        $values = [];

        foreach ($decrypted as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value;
            }
        }

        return new Credentials($values);
    }
}
