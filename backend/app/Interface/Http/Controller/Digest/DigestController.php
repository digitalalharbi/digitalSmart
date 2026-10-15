<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Digest;

use App\Application\Digest\Contract\DigestStore;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Attention\DigestKind;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** The caller's own digests. Keyed by the authenticated user; no parameter could name somebody else's. */
final class DigestController extends Controller
{
    public function index(DigestStore $store, ActorContext $actor): JsonResponse
    {
        $rows = $store->latest($actor->workspaceId()->value, $actor->userId()->value, 6);

        return response()->json(['data' => array_map(static fn (array $d): array => $d + [
            'label' => DigestKind::from($d['kind'])->label(),
        ], $rows)]);
    }

    public function read(string $digest, DigestStore $store, ActorContext $actor, Clock $clock): JsonResponse
    {
        $store->markRead($actor->workspaceId()->value, $actor->userId()->value, $digest, $clock->now());

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
