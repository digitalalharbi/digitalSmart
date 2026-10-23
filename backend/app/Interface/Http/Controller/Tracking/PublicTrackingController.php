<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Tracking;

use App\Application\Tracking\Command\FollowTrackingLink;
use App\Application\Tracking\Command\ReceiveTrackingEvent;
use App\Domain\Tracking\ConsentState;
use App\Domain\Tracking\TrackingEventName;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response as Http;

/**
 * The two addresses the public reaches: following a link, and a page reporting what happened after.
 *
 * Neither authenticates, neither says anything about the tenant, and neither can be made to go anywhere the link's owner
 * did not choose. A link that does not work — never existed, paused, expired — answers one bare 404.
 */
final class PublicTrackingController extends Controller
{
    private const int MAX_BODY = 4096;

    public function follow(Request $request, string $token, FollowTrackingLink $follow): Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $cookie = $request->cookie('ds_v');
        $result = $follow(
            $token,
            $request->query(),
            $request->userAgent(),
            $request->headers->get('referer'),
            is_string($cookie) ? $cookie : null,
        );

        if ($result === null) {
            return response('Not found', Http::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain', 'Cache-Control' => 'no-store']);
        }

        return redirect()->away($result['url'], Http::HTTP_FOUND, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer-when-downgrade'])
            ->withCookie(Cookie::make('ds_v', $result['visitor_id'], 60 * 24 * 180, '/', null, $request->isSecure(), true, false, 'Lax'));
    }

    /**
     * A beacon from a destination page. Read from the raw body so the page can send `text/plain` and avoid a
     * cross-origin preflight; the answer is the same whatever happened, so it cannot be used to probe.
     */
    public function event(Request $request, ReceiveTrackingEvent $receive): JsonResponse
    {
        $raw = $request->getContent();
        $body = strlen($raw) <= self::MAX_BODY ? json_decode($raw, true) : null;

        if (is_array($body)) {
            $name = is_string($body['event'] ?? null) ? TrackingEventName::tryFrom($body['event']) : null;
            $touchpoint = $body['touchpoint'] ?? null;
            $eventId = $body['event_id'] ?? null;

            if (
                $name !== null
                && is_string($touchpoint) && preg_match('/^[A-Za-z0-9]{20}$/', $touchpoint) === 1
                && is_string($eventId) && preg_match('/^[A-Za-z0-9_\-]{8,40}$/', $eventId) === 1
            ) {
                $receive(
                    $touchpoint,
                    $name,
                    $eventId,
                    is_string($body['consent'] ?? null) ? ConsentState::tryFrom($body['consent']) : null,
                    is_string($body['path'] ?? null) ? $body['path'] : null,
                    is_array($body['meta'] ?? null) ? $body['meta'] : [],
                );
            }
        }

        return response()->json(['data' => ['received' => true]], Http::HTTP_ACCEPTED);
    }
}
