<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Reporting;

use App\Application\Reporting\Query\ReadSharedReport;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reading a report through a shared link, with no account.
 *
 * The only unauthenticated read in this product. Three things about it are deliberate.
 *
 * **The token is in the path and nothing else is.** There is no report id, no client id, no date parameter — no
 * input a visitor could change to reach a different document. The token authorises exactly one report.
 *
 * **It always answers 200 with a state.** A revoked or expired link gets a sentence, not a 404: a link a client was
 * sent returning "not found" reads as a broken site, and they ask for it again rather than learning it lapsed. A
 * token nobody issued gets the same answer as a revoked one, because telling a visitor the difference tells anybody
 * guessing tokens when they have guessed one.
 *
 * **It is rate-limited separately**, in `routes/api.php`. The authenticated API's limit is per user; this one has no
 * user, so it is per IP and much tighter — a bare token in a URL is the one thing in this product worth brute
 * forcing.
 */
final class SharedReportController extends Controller
{
    public function __invoke(string $token, ReadSharedReport $read): JsonResponse
    {
        $result = $read($token);

        return response()->json([
            'data' => [
                'state' => $result['state']->value,
                /* Null when the link works. Otherwise the sentence a visitor reads instead of a report. */
                'message' => $result['message'],
                'mode' => $result['mode']?->value,
                'recipient_label' => $result['recipient'],
                'report' => $result['payload'],
            ],
        ], Response::HTTP_OK);
    }
}
