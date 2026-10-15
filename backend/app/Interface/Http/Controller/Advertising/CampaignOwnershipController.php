<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Advertising;

use App\Application\Advertising\Command\AssignCampaignsToProject;
use App\Application\Advertising\Command\AssignCampaignToProject;
use App\Application\Advertising\DTO\CampaignOwnershipView;
use App\Application\Advertising\Query\ListCampaignOwnership;
use App\Domain\Advertising\CampaignOwnership;
use App\Domain\Advertising\Exception\CampaignCannotJoinProject;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Advertising\CampaignOwnershipResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which project a campaign belongs to, and the queue of the ones nobody has placed.
 *
 * Rule 9's review surface: discovered data that has not been assigned must neither disappear nor be
 * silently bound, which leaves exactly one option — somebody can find it.
 */
final class CampaignOwnershipController extends Controller
{
    public function index(Request $request, ListCampaignOwnership $campaigns): JsonResponse
    {
        $only = [];

        foreach ((array) $request->query('ownership', []) as $value) {
            $state = is_string($value) ? CampaignOwnership::tryFrom($value) : null;

            if ($state === null) {
                return $this->unknown('ownership', CampaignOwnership::values());
            }

            $only[] = $state;
        }

        $result = $campaigns(
            $only,
            $this->intOrNull($request, 'limit'),
            $this->intOrNull($request, 'offset') ?? 0,
        );

        return response()->json([
            'data' => array_map(
                static fn (CampaignOwnershipView $campaign): array => CampaignOwnershipResource::one($campaign),
                $result['campaigns'],
            ),
            'meta' => [
                'counts' => $result['counts'],
                'limit' => $result['limit'],
                'offset' => $result['offset'],
            ] + CampaignOwnershipResource::vocabulary(),
        ]);
    }

    public function assign(
        Request $request,
        string $campaign,
        AssignCampaignToProject $assign,
    ): JsonResponse {
        $validated = $request->validate([
            /*
             * Present and null is a real instruction — "return this campaign to its account's bindings" —
             * so the field is required and nullable rather than optional, and omitting it is a mistake
             * rather than a clear.
             */
            'project_id' => ['present', 'nullable', 'uuid'],
        ]);

        $projectId = $validated['project_id'] ?? null;

        try {
            $assigned = $assign($campaign, is_string($projectId) ? $projectId : null);
        } catch (CampaignCannotJoinProject $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['project_id' => [$exception->getMessage()]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => CampaignOwnershipResource::one($assigned)]);
    }

    /** Many campaigns into one project. Partial by design: the response says which were refused and why. */
    public function assignMany(Request $request, AssignCampaignsToProject $assign): JsonResponse
    {
        $validated = $request->validate([
            'campaign_ids' => ['required', 'array', 'min:1', 'max:'.AssignCampaignsToProject::MAX],
            'campaign_ids.*' => ['uuid'],
            'project_id' => ['required', 'uuid'],
        ]);

        /** @var list<string> $ids */
        $ids = array_values(array_filter((array) $validated['campaign_ids'], 'is_string'));
        $projectId = is_string($validated['project_id']) ? $validated['project_id'] : '';

        return response()->json(['data' => $assign($ids, $projectId)]);
    }

    /** @param list<string> $allowed */
    private function unknown(string $field, array $allowed): JsonResponse
    {
        return response()->json([
            'message' => sprintf('Unknown %s.', str_replace('_', ' ', $field)),
            'code' => 'invalid_'.$field,
            'allowed' => $allowed,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function intOrNull(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
