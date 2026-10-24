<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Campaign;

use App\Application\Campaign\DTO\ExecutionRecord;
use App\Application\Campaign\Launch\ApproveCampaignPlan;
use App\Application\Campaign\Launch\LaunchCampaignPlan;
use App\Application\Campaign\Launch\RecoverExecution;
use App\Application\Campaign\Query\ReadLaunches;
use App\Interface\Http\Controller\Controller;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;

/** Approve, launch, and follow a campaign plan's creation on its platforms. Nothing here waits for a platform. */
final class LaunchController extends Controller
{
    public function approve(string $blueprint, ApproveCampaignPlan $approve): JsonResponse
    {
        return response()->json(['data' => $approve($blueprint)]);
    }

    public function launch(string $blueprint, LaunchCampaignPlan $launch): JsonResponse
    {
        return response()->json(['data' => $launch($blueprint)], 202);
    }

    public function index(string $blueprint, ReadLaunches $read, RecoverExecution $recover): JsonResponse
    {
        return response()->json(['data' => array_map(static fn (ExecutionRecord $e): array => self::execution($e, $recover), $read($blueprint))]);
    }

    public function reconcile(string $execution, RecoverExecution $recover): JsonResponse
    {
        return response()->json(['data' => self::execution($recover->reconcile($execution), $recover)]);
    }

    public function retry(string $execution, RecoverExecution $recover): JsonResponse
    {
        return response()->json(['data' => self::execution($recover->retry($execution), $recover)], 202);
    }

    /** @return array<string, mixed> */
    private static function execution(ExecutionRecord $e, RecoverExecution $recover): array
    {
        return [
            'id' => $e->id,
            'launch_id' => $e->launchId,
            'provider' => $e->provider,
            'external_account_id' => $e->externalAccountId,
            'attempt' => $e->attempt,
            'status' => $e->status->value,
            'source' => $e->source,
            'provider_ids' => ['campaign' => $e->providerCampaignId, 'ad_group' => $e->providerAdGroupId, 'ads' => $e->providerAdIds],
            'readback' => $e->readback,
            'failure_classification' => $e->failureClassification,
            'message' => $e->message,
            'may_retry' => $e->status->mayRetry(),
            'may_reconcile' => $recover->mayReconcile($e),
            'request_started_at' => $e->requestStartedAt?->format(DateTimeInterface::ATOM),
            'request_finished_at' => $e->requestFinishedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
