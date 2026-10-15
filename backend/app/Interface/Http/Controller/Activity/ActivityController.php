<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Activity;

use App\Application\Activity\DTO\ActivityItem;
use App\Application\Activity\Query\GetActivityFeed;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A plain-language feed of what the agency has been doing, scoped to what the reader may see. */
final class ActivityController extends Controller
{
    public function __invoke(Request $request, GetActivityFeed $feed): JsonResponse
    {
        return response()->json(['data' => array_map(static fn (ActivityItem $i): array => [
            'id' => $i->id,
            'action' => $i->action,
            'sentence' => $i->sentence,
            'actor' => $i->actorName,
            'entity' => ['type' => $i->entityType, 'id' => $i->entityId],
            'project_id' => $i->projectId,
            'client_id' => $i->clientId,
            'at' => $i->at->format(DATE_ATOM),
        ], $feed($request->integer('limit') ?: 30))]);
    }
}
