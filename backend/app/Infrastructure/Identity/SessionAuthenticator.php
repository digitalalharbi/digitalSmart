<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\Contract\Authenticator;
use App\Domain\Identity\Email;
use App\Domain\Identity\UserId;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final readonly class SessionAuthenticator implements Authenticator
{
    public function __construct(
        private StatefulGuard $guard,
        private Request $request,
    ) {}

    public function attempt(Email $email, string $password, bool $remember = false): ?UserId
    {
        // is_active is part of the credential check, so a deactivated account
        // cannot authenticate even with a correct password.
        $attempted = $this->guard->attempt(
            ['email' => $email->value, 'password' => $password, 'is_active' => true],
            $remember,
        );

        if (! $attempted) {
            return null;
        }

        // Fixation defence: the session id must change on privilege escalation.
        // Token clients have no session, so this is conditional.
        if ($this->request->hasSession()) {
            $this->request->session()->regenerate();
        }

        $user = $this->guard->user();

        if (! $user instanceof User) {
            return null;
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return new UserId((string) $user->getKey());
    }

    public function logout(): void
    {
        $this->guard->logout();

        if (! $this->request->hasSession()) {
            return;
        }

        $session = $this->request->session();
        $session->invalidate();
        $session->regenerateToken();
    }

    public function currentUserId(): ?UserId
    {
        $user = $this->guard->user();

        return $user instanceof User ? new UserId((string) $user->getKey()) : null;
    }

    public function confirmPassword(UserId $userId, string $password): bool
    {
        $user = User::query()->whereKey($userId->value)->first();

        if (! $user instanceof User) {
            return false;
        }

        /*
         * `Hash::check` rather than `attempt()`. Signing in again would regenerate the session, write a
         * `last_login_at` and record a sign-in that did not happen — three side effects for a question that
         * should have none.
         */
        return Hash::check($password, (string) $user->getAttribute('password'));
    }

    public function replacePassword(UserId $userId, string $password): void
    {
        /*
         * The **signed-in instance** where it is the same person, not a fresh copy of the row.
         *
         * This looks like a detail and decides whether the caller stays signed in. `AuthenticateSessionForSpa`
         * re-stores `$request->user()->getAuthPassword()` in the session after the response, so if the new hash
         * were written to a different object the session would keep the *old* hash — and the next request would
         * compare it against the new one and sign this browser out. Which is the behaviour wanted for every
         * other session and precisely wrong for the one being used.
         *
         * Caught by the test asserting the product opens again afterwards: it came back `401`.
         */
        $signedIn = $this->guard->user();

        $user = $signedIn instanceof User && (string) $signedIn->getKey() === $userId->value
            ? $signedIn
            : User::query()->whereKey($userId->value)->firstOrFail();

        /*
         * The borrowed-password flag goes with it. Somebody added by an administrator signs in with a password
         * that administrator read out; replacing it is the moment it stops working, and leaving the flag set
         * would lock them out of the product they had just been given access to.
         */
        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => false,
        ])->saveQuietly();

        /*
         * Saving the hash is what ends the other sessions, and there is nothing else to call.
         *
         * `AuthenticateSessionForSpa` compares each session's stored password hash against the user's current
         * one, so every session opened with the old password now fails its next request. This one survives:
         * the middleware re-stores the hash after this response, which is the behaviour that keeps somebody
         * signed in on the screen they are looking at.
         *
         * `logoutOtherDevices()` is deliberately not called. It re-hashes the same password to invalidate
         * sessions, which is for the case where the password is *not* changing — here the hash has already
         * changed, and calling it would hash twice for the same effect.
         */
    }

    public function mustChoosePassword(UserId $userId): bool
    {
        $user = User::query()->whereKey($userId->value)->first();

        return $user instanceof User && $user->mustChoosePassword();
    }

    public function isDeactivated(Email $email): bool
    {
        return User::query()
            ->where('email', $email->value)
            ->where('is_active', false)
            ->exists();
    }
}
