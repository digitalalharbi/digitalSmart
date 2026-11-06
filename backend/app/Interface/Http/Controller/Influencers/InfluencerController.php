<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Influencers;

use App\Application\Influencers\Command\SaveInfluencer;
use App\Application\Influencers\DTO\InfluencerInput;
use App\Application\Influencers\DTO\InfluencerView;
use App\Application\Influencers\Query\ListInfluencers;
use App\Application\Tracking\Exception\TrackingRefused;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The people and accounts that promote a client's offer. Reading needs `tracking.view`, writing `tracking.manage`, and both
 * are enforced in the application layer; a reader sees only the influencers of clients they can see.
 */
final class InfluencerController extends Controller
{
    public function index(Request $request, ListInfluencers $list): JsonResponse
    {
        $client = $request->query('client_id');

        return response()->json(['data' => array_map(
            $this->resource(...),
            $list(is_string($client) && $client !== '' ? $client : null, $request->boolean('archived')),
        )]);
    }

    public function show(string $influencer, ListInfluencers $list): JsonResponse
    {
        return response()->json(['data' => $this->resource($list->one($influencer))]);
    }

    public function store(Request $request, SaveInfluencer $save): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->save($request, $save, null))], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $influencer, SaveInfluencer $save): JsonResponse
    {
        return response()->json(['data' => $this->resource($this->save($request, $save, $influencer))]);
    }

    private function save(Request $request, SaveInfluencer $save, ?string $id): InfluencerView
    {
        $data = $request->validate([
            'client_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'handle' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', 'in:active,archived'],
        ]);

        $text = static fn (string $key): ?string => isset($data[$key]) && is_string($data[$key]) && $data[$key] !== '' ? $data[$key] : null;

        try {
            return $save(new InfluencerInput(
                clientId: (string) $data['client_id'],
                name: (string) $data['name'],
                handle: $text('handle'),
                platform: $text('platform'),
                notes: $text('notes'),
                status: $text('status') ?? 'active',
            ), $id);
        } catch (TrackingRefused $e) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function resource(InfluencerView $i): array
    {
        return [
            'id' => $i->id,
            'client' => ['id' => $i->clientId, 'name' => $i->clientName],
            'name' => $i->name,
            'handle' => $i->handle,
            'platform' => $i->platform,
            'notes' => $i->notes,
            'status' => $i->status,
            'created_at' => $i->createdAt->format(DATE_ATOM),
            /* Valid traffic only, summed over this influencer's links. Never a fee and never a cost: none is recorded. */
            'totals' => ['links' => $i->links, 'clicks' => $i->clicks, 'visitors' => $i->visitors],
        ];
    }
}
