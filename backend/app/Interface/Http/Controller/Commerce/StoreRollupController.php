<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Commerce;

use App\Application\Commerce\DTO\StoreRollupRow;
use App\Application\Commerce\Query\GetStoreAnalytics;
use App\Application\Commerce\Query\GetStoreRollup;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Commerce\RevenueSource;
use App\Interface\Http\Controller\Controller;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every store at once. Same rule as the single-client view: the revenue source is named, never defaulted.
 */
final class StoreRollupController extends Controller
{
    private const int DEFAULT_DAYS = 30;

    public function __invoke(Request $request, GetStoreRollup $rollup, AuthorizationGuard $guard): JsonResponse
    {
        $guard->ensure(GetStoreAnalytics::PERMISSION);

        $source = RevenueSource::tryFrom($request->string('revenue_source')->value());

        if ($source === null) {
            return response()->json([
                'message' => 'A revenue source is required. A figure from one authority cannot be mixed with another.',
                'code' => 'revenue_source_required',
                'allowed' => RevenueSource::values(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $until = $this->date($request->string('until')->value(), ' 23:59:59') ?? new DateTimeImmutable('today 23:59:59');
        $from = $this->date($request->string('from')->value(), ' 00:00:00')
            ?? $until->modify('-'.(self::DEFAULT_DAYS - 1).' days')->setTime(0, 0);

        if ($from > $until) {
            return response()->json(['message' => 'The start of the period is after its end.', 'code' => 'invalid_period'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $clientId = $request->string('client_id')->value() ?: null;

        return response()->json([
            'data' => [
                'revenue_source' => $source->value,
                'from' => $from->format('Y-m-d'),
                'until' => $until->format('Y-m-d'),
                /* Not totalled: stores in different currencies have no sum, and the client column is the unit. */
                'stores' => array_map(static fn (StoreRollupRow $r): array => [
                    'store_id' => $r->storeId,
                    'store_name' => $r->storeName,
                    'provider' => $r->provider,
                    'client_id' => $r->clientId,
                    'client_name' => $r->clientName,
                    'currency' => $r->currency,
                    'is_active' => $r->isActive,
                    'orders' => $r->orders,
                    'revenue_minor' => $r->revenueMinor,
                    'refunded_minor' => $r->refundedMinor,
                    'net_revenue_minor' => $r->netRevenueMinor,
                    'average_order_minor' => $r->averageOrderMinor,
                    'attributed_revenue_minor' => $r->attributedRevenueMinor,
                    'last_synced_at' => $r->lastSyncedAt?->format(DATE_ATOM),
                ], $rollup($source, $from, $until, $clientId)),
            ],
        ]);
    }

    private function date(string $value, string $time): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value.$time);
        } catch (Exception) {
            return null;
        }
    }
}
