<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Operational notification routing is configured during production hardening.
    }

    /**
     * Who may open the Horizon dashboard outside local development.
     *
     * Closed by default: until platform roles exist (Phase 1) nobody is granted
     * access in a deployed environment. This fails closed on purpose — an open
     * queue dashboard exposes client job payloads.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', static function (?Authenticatable $user = null): bool {
            if ($user === null) {
                return false;
            }

            $allowed = array_values(array_filter(array_map(
                'trim',
                explode(',', (string) config('horizon.allowed_emails', '')),
            )));

            $email = $user->getAttribute('email');

            return $allowed !== [] && is_string($email) && in_array($email, $allowed, true);
        });
    }
}
