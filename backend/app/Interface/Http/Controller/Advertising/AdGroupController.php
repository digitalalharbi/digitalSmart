<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Advertising;

use App\Application\Advertising\DTO\AdGroupFilter;
use App\Application\Advertising\DTO\CampaignControlFilter;
use App\Application\Advertising\Query\ListAdGroups;
use App\Application\Shared\Contract\ActorContext;
use App\Domain\Advertising\CampaignObjective;
use App\Domain\Advertising\EntityStatus;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Advertising\AdGroupResource;
use App\Interface\Http\Support\ControlFilterInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Ad Groups screen: every ad group the reader may see, across clients, projects and platforms.
 *
 * A first-class screen, not a drawer under a campaign — an operator looking for the ad set that is dragging a
 * campaign down should not have to open the campaign first.
 */
final class AdGroupController extends Controller
{
    public function index(Request $request, ListAdGroups $adGroups, ActorContext $actor): JsonResponse
    {
        $statuses = ControlFilterInput::enums($request, 'status', EntityStatus::class);

        if ($statuses === false) {
            return ControlFilterInput::unknown('status', array_map(
                static fn (EntityStatus $status): string => $status->value,
                EntityStatus::cases(),
            ));
        }

        $objectives = ControlFilterInput::enums($request, 'objective', CampaignObjective::class);

        if ($objectives === false) {
            return ControlFilterInput::unknown('objective', array_map(
                static fn (CampaignObjective $objective): string => $objective->value,
                CampaignObjective::cases(),
            ));
        }

        $sort = $request->query('sort', 'spend');

        if (! is_string($sort) || ! in_array($sort, AdGroupFilter::sorts(), true)) {
            return ControlFilterInput::unknown('sort', AdGroupFilter::sorts());
        }

        $search = $request->query('search');

        $page = $adGroups(
            new CampaignControlFilter(
                clientIds: ControlFilterInput::uuids($request, 'client_id'),
                projectIds: ControlFilterInput::projectScope($request, $actor),
                providers: ControlFilterInput::strings($request, 'provider'),
                externalAccountIds: ControlFilterInput::uuids($request, 'external_account_id'),
                objectives: $objectives,
                campaignIds: ControlFilterInput::uuids($request, 'campaign_id'),
            ),
            new AdGroupFilter(
                statuses: $statuses,
                search: is_string($search) && trim($search) !== '' ? trim($search) : null,
                adGroupIds: ControlFilterInput::uuids($request, 'ad_group_id'),
                reportedNoResults: $request->boolean('no_results'),
                sort: $sort,
                descending: $request->query('direction') === 'desc',
            ),
            ControlFilterInput::intOrNull($request, 'limit'),
            ControlFilterInput::intOrNull($request, 'offset') ?? 0,
            ControlFilterInput::intOrNull($request, 'days'),
        );

        return response()->json(AdGroupResource::page($page));
    }
}
