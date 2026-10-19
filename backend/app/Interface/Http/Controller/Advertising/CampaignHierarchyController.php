<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Advertising;

use App\Application\Advertising\Query\GetCampaignHierarchy;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;

/** A campaign's ad groups and ads, for the expandable rows on the Campaigns screen. */
final class CampaignHierarchyController extends Controller
{
    public function __invoke(string $campaign, GetCampaignHierarchy $hierarchy): JsonResponse
    {
        return response()->json(['data' => $hierarchy($campaign)]);
    }
}
