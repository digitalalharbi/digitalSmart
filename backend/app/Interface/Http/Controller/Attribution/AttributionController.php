<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Attribution;

use App\Application\Attribution\Exception\ModelNotAvailable;
use App\Application\Attribution\Query\CompareAttributionModels;
use App\Application\Attribution\Query\GetAttribution;
use App\Domain\Attribution\AttributionModel;
use App\Domain\Commerce\RevenueSource;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Attribution\AttributionResource;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attribution: which campaign earned the money, under a model the caller has to name.
 *
 * Every endpoint here requires both a model and a revenue source, with no defaults. A default on
 * either would be this product deciding, quietly, which campaign a client should be told earned their
 * month — and the two decisions compound: "last-recorded revenue from Salla" and "first-recorded
 * revenue from GA4" are different claims about the same period, and neither is wrong.
 */
final class AttributionController extends Controller
{
    /** The window used when a caller gives none. A month is what an agency reviews. */
    private const int DEFAULT_DAYS = 30;

    /** The catalogue, including the models that cannot be computed and why. */
    public function models(): JsonResponse
    {
        return response()->json(['data' => AttributionResource::models()]);
    }

    public function show(Request $request, GetAttribution $attribution): JsonResponse
    {
        $model = AttributionModel::tryFrom($request->string('model')->value());

        if ($model === null) {
            return response()->json([
                'message' => 'An attribution model is required. Each one answers a different question, '
                    .'so there is no default.',
                'code' => 'model_required',
                'allowed' => AttributionModel::values(),
                'available' => array_map(
                    static fn (AttributionModel $one): string => $one->value,
                    AttributionModel::available(),
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $revenueSource = $this->revenueSource($request);

        if (! $revenueSource instanceof RevenueSource) {
            return $revenueSource;
        }

        $period = $this->period($request);

        if (! is_array($period)) {
            return $period;
        }

        try {
            $view = $attribution(
                model: $model,
                revenueSource: $revenueSource,
                from: $period['from'],
                until: $period['until'],
                clientId: $this->scopeId($request, 'client_id'),
                lookbackDays: (int) ($request->integer('lookback_days') ?: 30),
                limit: (int) ($request->integer('limit') ?: 20),
            );
        } catch (ModelNotAvailable $refusal) {
            /*
             * 422 rather than 501: the request is well formed and the server understands it, and what
             * cannot be satisfied is the data behind it. The body carries what is missing and what
             * would unblock it, because the person asking is usually deciding whether to pay for it.
             */
            return response()->json([
                'message' => $refusal->getMessage(),
                'code' => 'model_not_available',
                'model' => $refusal->model->value,
                'missing' => $refusal->missing,
                'would_unblock' => $refusal->wouldUnblock,
                'available' => array_map(
                    static fn (AttributionModel $one): string => $one->value,
                    AttributionModel::available(),
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => AttributionResource::view($view)]);
    }

    /**
     * The same period under every model that can be computed.
     *
     * The endpoint worth having. One model's figures invite a reader to treat them as what happened;
     * all of them together make the true statement available — that attribution is a choice, and here
     * is what that choice is worth on this client's own campaigns.
     */
    public function comparison(Request $request, CompareAttributionModels $compare): JsonResponse
    {
        $revenueSource = $this->revenueSource($request);

        if (! $revenueSource instanceof RevenueSource) {
            return $revenueSource;
        }

        $period = $this->period($request);

        if (! is_array($period)) {
            return $period;
        }

        return response()->json([
            'data' => AttributionResource::comparison($compare(
                revenueSource: $revenueSource,
                from: $period['from'],
                until: $period['until'],
                clientId: $this->scopeId($request, 'client_id'),
                lookbackDays: (int) ($request->integer('lookback_days') ?: 30),
                limit: (int) ($request->integer('limit') ?: 20),
            )),
        ]);
    }

    /** The revenue authority, or the refusal to answer without one. */
    private function revenueSource(Request $request): RevenueSource|JsonResponse
    {
        $source = RevenueSource::tryFrom($request->string('revenue_source')->value());

        return $source ?? response()->json([
            'message' => 'A revenue source is required. A figure from one authority cannot be mixed with another.',
            'code' => 'revenue_source_required',
            'allowed' => RevenueSource::values(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @return array{from: DateTimeImmutable, until: DateTimeImmutable}|JsonResponse */
    private function period(Request $request): array|JsonResponse
    {
        $until = $this->date($request, 'until') ?? new DateTimeImmutable('today 23:59:59');
        $from = $this->date($request, 'from')
            ?? $until->modify('-'.(self::DEFAULT_DAYS - 1).' days')->setTime(0, 0);

        if ($from > $until) {
            return response()->json([
                'message' => 'The start of the period is after its end.',
                'code' => 'invalid_period',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ['from' => $from, 'until' => $until];
    }

    private function date(Request $request, string $key): ?DateTimeImmutable
    {
        $value = $request->string($key)->value();

        if ($value === '') {
            return null;
        }

        try {
            // `until` is inclusive to the end of its day, so a single-day request covers that day.
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
