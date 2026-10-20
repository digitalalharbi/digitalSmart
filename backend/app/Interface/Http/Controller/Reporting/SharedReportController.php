<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Reporting;

use App\Application\Reporting\DTO\SharedFilterRequest;
use App\Application\Reporting\Query\ReadSharedReport;
use App\Domain\Reporting\Exception\FilterOutsideScope;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reading a report through a shared link, with no account.
 *
 * The only unauthenticated read in this product. Three things about it are deliberate.
 *
 * **The token is in the path and it is the only authority.** There is no report id and no client id — no input a
 * visitor could change to reach a different document. The query string may *narrow* what the token already shows
 * (a platform, a campaign, a store, a sub-range of the period) and can never widen it: every value is looked up inside
 * the link's own scope, and one that is not found is refused.
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
    public function __invoke(string $token, Request $request, ReadSharedReport $read): JsonResponse
    {
        try {
            $result = $read($token, $this->filter($request));
        } catch (FilterOutsideScope $refusal) {
            /*
             * Not a 404 and not a silent drop: the visitor is told the selection is not part of this report, and the
             * page falls back to the whole of it.
             */
            return response()->json([
                'message' => $refusal->getMessage(),
                'code' => 'filter_out_of_scope',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => [
                'state' => $result['state']->value,
                /* Null when the link works. Otherwise the sentence a visitor reads instead of a report. */
                'message' => $result['message'],
                'mode' => $result['mode']?->value,
                'recipient_label' => $result['recipient'],
                'report' => $result['payload'],
                'filters' => $result['filters'],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * The filters the query string asks for, shape-checked and nothing more.
     *
     * Whether a value is *allowed* is not decided here. Here a value is only a short, plain token or a date; whether it
     * names something inside this link's scope is `NarrowSharedReport`'s question, and the answer never depends on the
     * request.
     */
    private function filter(Request $request): SharedFilterRequest
    {
        $list = function (string $key) use ($request): array {
            $raw = $request->query($key);
            $values = is_array($raw) ? $raw : (is_string($raw) ? explode(',', $raw) : []);

            return array_slice(array_values(array_unique(array_filter(
                array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $values),
                static fn (string $value): bool => preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $value) === 1,
            ))), 0, 20);
        };

        $date = static function (mixed $value): ?DateTimeImmutable {
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return null;
            }

            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $parsed === false ? null : $parsed;
        };

        $store = $request->query('store');

        return new SharedFilterRequest(
            platforms: $list('platform'),
            campaigns: $list('campaign'),
            store: is_string($store) && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $store) === 1 ? $store : null,
            from: $date($request->query('from')),
            until: $date($request->query('until')),
            refinement: $request->query('refine') === '1',
        );
    }
}
