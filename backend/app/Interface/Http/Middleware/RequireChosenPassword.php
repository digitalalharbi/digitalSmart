<?php

declare(strict_types=1);

namespace App\Interface\Http\Middleware;

use App\Application\Identity\Contract\Authenticator;
use App\Domain\Identity\UserId;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A one-time password buys exactly one thing: the chance to choose a real one.
 *
 * Somebody added by an administrator signs in with a password that administrator read out to them. Until they
 * replace it, this refuses every request except the three that let them: reading their own session, changing
 * the password, and signing out. Without that, "an administrator knows your password" would be true
 * indefinitely, and the add-a-colleague flow would not have been worth shipping.
 *
 * **A refusal with a machine-readable code rather than a redirect.** This is an API; the SPA reads
 * `password_change_required` and opens the dialog. A 403 rather than a 401, deliberately: the session is valid
 * and the credentials are accepted — what is missing is a step, not an identity, and answering 401 would send
 * the SPA to sign in again with the very password it is being told to replace.
 */
final readonly class RequireChosenPassword
{
    /**
     * What a member with a borrowed password may still reach.
     *
     * Route names rather than paths, so a renamed URL cannot quietly widen this. `auth.me` is here because the
     * SPA cannot render anything — including the dialog — without knowing who it is talking to.
     *
     * @var list<string>
     */
    private const array ALLOWED = ['auth.me', 'auth.password', 'auth.logout'];

    public function __construct(private Authenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->getAuthIdentifier();

        if (! is_string($userId) || ! $this->authenticator->mustChoosePassword(new UserId($userId))) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Choose your own password before going any further. The one you signed in with was '
                .'given to you by somebody else, and it stops working as soon as you replace it.',
            'code' => 'password_change_required',
        ], Response::HTTP_FORBIDDEN);
    }
}
