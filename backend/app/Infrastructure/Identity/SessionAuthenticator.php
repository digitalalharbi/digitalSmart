<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\Contract\Authenticator;
use App\Domain\Identity\Email;
use App\Domain\Identity\UserId;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;

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

    public function isDeactivated(Email $email): bool
    {
        return User::query()
            ->where('email', $email->value)
            ->where('is_active', false)
            ->exists();
    }
}
