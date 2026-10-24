<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Shortcuts;

use App\Application\Search\DTO\SearchHit;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Application\Shortcuts\Contract\ShortcutStore;
use App\Application\Shortcuts\Query\GetShortcuts;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins and recents. Personal by construction: every read and write is keyed by the authenticated user, with
 * no parameter that could name somebody else's.
 */
final class ShortcutController extends Controller
{
    private const array TYPES = ['client', 'project', 'campaign'];

    private const int RECENTS_KEPT = 20;

    public function index(GetShortcuts $shortcuts): JsonResponse
    {
        $result = $shortcuts();
        $present = static fn (SearchHit $h): array => [
            'type' => $h->type, 'id' => $h->id, 'label' => $h->label, 'context' => $h->context, 'path' => $h->path,
        ];

        return response()->json(['data' => [
            'pinned' => array_map($present, $result['pinned']),
            'recent' => array_map($present, $result['recent']),
        ]]);
    }

    public function pin(string $type, string $id, ShortcutStore $store, ActorContext $actor, Clock $clock): JsonResponse
    {
        if (! in_array($type, self::TYPES, true)) {
            return $this->unknown();
        }

        if (! $store->belongs($actor->workspaceId()->value, $type, $id)) {
            return $this->notFound();
        }

        $store->pin($actor->workspaceId()->value, $actor->userId()->value, $type, $id, $clock->now());

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function unpin(string $type, string $id, ShortcutStore $store, ActorContext $actor): JsonResponse
    {
        if (! in_array($type, self::TYPES, true)) {
            return $this->unknown();
        }

        /* Idempotent for this workspace's own things; another workspace's id is not found, never a quiet success. */
        if (! $store->belongs($actor->workspaceId()->value, $type, $id)) {
            return $this->notFound();
        }

        $store->unpin($actor->workspaceId()->value, $actor->userId()->value, $type, $id);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function visit(Request $request, ShortcutStore $store, ActorContext $actor, Clock $clock): JsonResponse
    {
        $validated = $request->validate(['type' => ['required', 'string'], 'id' => ['required', 'uuid']]);

        if (! in_array($validated['type'], self::TYPES, true)) {
            return $this->unknown();
        }

        if (! $store->belongs($actor->workspaceId()->value, (string) $validated['type'], (string) $validated['id'])) {
            return $this->notFound();
        }

        $store->touch(
            $actor->workspaceId()->value,
            $actor->userId()->value,
            (string) $validated['type'],
            (string) $validated['id'],
            $clock->now(),
            self::RECENTS_KEPT,
        );

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Not found rather than forbidden: the answer must not confirm that an id exists in another workspace. */
    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not found.'], Response::HTTP_NOT_FOUND);
    }

    private function unknown(): JsonResponse
    {
        return response()->json(['message' => 'Unknown type.', 'code' => 'invalid_type', 'allowed' => self::TYPES], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
