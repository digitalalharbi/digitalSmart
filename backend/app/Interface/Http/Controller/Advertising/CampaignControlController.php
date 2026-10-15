<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Advertising;

use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\Query\GetCampaignControl;
use App\Domain\Advertising\CampaignHealth;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\EntityStatus;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Advertising\CampaignControlResource;
use BackedEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Campaign Control Center.
 *
 * One place answering "which of our campaigns needs me", across every client, project, provider and
 * account the reader is allowed to see — so that normal monitoring does not mean opening six platforms.
 */
final class CampaignControlController extends Controller
{
    public function index(Request $request, GetCampaignControl $campaigns): JsonResponse
    {
        $statuses = $this->enums($request, 'status', EntityStatus::class);

        if ($statuses === false) {
            return $this->unknown('status', array_map(
                static fn (EntityStatus $status): string => $status->value,
                EntityStatus::cases(),
            ));
        }

        $objectives = $this->enums($request, 'objective', CampaignObjective::class);

        if ($objectives === false) {
            return $this->unknown('objective', array_map(
                static fn (CampaignObjective $objective): string => $objective->value,
                CampaignObjective::cases(),
            ));
        }

        $health = $this->enums($request, 'health', CampaignHealth::class);

        if ($health === false) {
            return $this->unknown('health', CampaignHealth::values());
        }

        $search = $request->query('search');
        $sort = $request->query('sort', 'health');

        if (! is_string($sort) || ! in_array($sort, CampaignControlFilter::sorts(), true)) {
            return $this->unknown('sort', CampaignControlFilter::sorts());
        }

        $filter = new CampaignControlFilter(
            clientIds: $this->uuids($request, 'client_id'),
            projectIds: $this->uuids($request, 'project_id'),
            providers: $this->strings($request, 'provider'),
            externalAccountIds: $this->uuids($request, 'external_account_id'),
            statuses: $statuses,
            objectives: $objectives,
            health: $health,
            search: is_string($search) && trim($search) !== '' ? trim($search) : null,
            sort: $sort,
            tagIds: $this->uuids($request, 'tag_id'),
            descending: $request->query('direction') === 'desc',
        );

        $page = $campaigns(
            $filter,
            $this->intOrNull($request, 'limit'),
            $this->intOrNull($request, 'offset') ?? 0,
        );

        return response()->json(CampaignControlResource::page($page));
    }

    /**
     * Read repeated enum parameters, or `false` when one of them is not a member.
     *
     * `false` rather than silently dropping it: a filter the server did not understand returns a page the
     * reader believes is narrowed and is not, which is worse than an error.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return list<T>|false
     */
    private function enums(Request $request, string $key, string $enum): array|false
    {
        $values = [];

        foreach ($this->strings($request, $key) as $value) {
            $case = $enum::tryFrom($value);

            if ($case === null) {
                return false;
            }

            $values[] = $case;
        }

        return $values;
    }

    /** @return list<string> */
    private function strings(Request $request, string $key): array
    {
        $raw = $request->query($key, $request->query($key.'s', []));
        $values = [];

        foreach (is_array($raw) ? $raw : [$raw] as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function uuids(Request $request, string $key): array
    {
        return array_values(array_filter(
            $this->strings($request, $key),
            static fn (string $value): bool => preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $value,
            ) === 1,
        ));
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
