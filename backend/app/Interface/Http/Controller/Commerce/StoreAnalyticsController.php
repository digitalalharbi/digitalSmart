<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Commerce;

use App\Application\Commerce\Query\GetStoreAnalytics;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Commerce\RevenueSource;
use App\Domain\Commerce\TrendGrain;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Commerce\StoreAnalyticsResource;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a client's stores sold.
 *
 * `revenue_source` is **required**. There is no default and no "all" value: a Salla total and a GA4 total
 * measure different things, and answering a request that did not say which would be answering a question
 * nobody asked.
 */
final class StoreAnalyticsController extends Controller
{
    /** The window used when a caller gives none. A month is what an agency reviews. */
    private const int DEFAULT_DAYS = 30;

    public function __invoke(
        Request $request,
        GetStoreAnalytics $analytics,
        AuthorizationGuard $guard,
    ): JsonResponse {
        /*
         * Before the validation below, not after. A caller who may not read revenue should be refused rather than
         * told which parameter they left out: a 422 naming `revenue_source` says the endpoint exists and would
         * answer, which is a sentence about the product nobody outside it has asked for. The use case checks the
         * same permission again, so neither placement makes the other redundant.
         */
        $guard->ensure(GetStoreAnalytics::PERMISSION);

        $revenueSource = RevenueSource::tryFrom($request->string('revenue_source')->value());

        if ($revenueSource === null) {
            return response()->json([
                'message' => 'A revenue source is required. A figure from one authority cannot be mixed with another.',
                'code' => 'revenue_source_required',
                'allowed' => RevenueSource::values(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $until = $this->date($request, 'until') ?? new DateTimeImmutable('today 23:59:59');
        $from = $this->date($request, 'from') ?? $until->modify('-'.(self::DEFAULT_DAYS - 1).' days')->setTime(0, 0);

        if ($from > $until) {
            return response()->json([
                'message' => 'The start of the period is after its end.',
                'code' => 'invalid_period',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $grain = TrendGrain::tryFrom($request->string('grain')->value() ?: TrendGrain::Day->value);

        if ($grain === null) {
            return response()->json([
                'message' => 'Choose a grain the trend has: day, week or month.',
                'code' => 'invalid_grain',
                'allowed' => TrendGrain::values(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $compare = $request->string('compare')->value();

        if (! in_array($compare, ['', 'none', 'previous'], true)) {
            return response()->json([
                'message' => 'The only comparison is with the period immediately before: compare=previous.',
                'code' => 'invalid_comparison',
                'allowed' => ['none', 'previous'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => StoreAnalyticsResource::view($analytics(
                revenueSource: $revenueSource,
                from: $from,
                until: $until,
                clientId: $this->scopeId($request, 'client_id'),
                storeId: $this->scopeId($request, 'store_id'),
                limit: (int) ($request->integer('limit') ?: 20),
                grain: $grain,
                comparePrevious: $compare === 'previous',
            )),
        ]);
    }

    private function date(Request $request, string $key): ?DateTimeImmutable
    {
        $value = $request->string($key)->value();

        if ($value === '') {
            return null;
        }

        try {
            /*
             * `until` is inclusive to the end of its day, so a single-day request covers that day. A
             * reader asking for "today" means today, not the instant midnight passed.
             */
            return new DateTimeImmutable($value.($key === 'until' ? ' 23:59:59' : ' 00:00:00'));
        } catch (Exception) {
            return null;
        }
    }

    private function scopeId(Request $request, string $key): ?string
    {
        return $request->string($key)->value() ?: null;
    }
}
