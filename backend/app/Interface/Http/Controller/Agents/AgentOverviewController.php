<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Agents;

use App\Application\Agents\Command\RunAgentsNow;
use App\Application\Agents\Query\GetAgentOverview;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;

final class AgentOverviewController extends Controller
{
    public function show(GetAgentOverview $overview): JsonResponse
    {
        return response()->json(['data' => [...$overview(), 'mcp_endpoint' => url('/api/mcp')]]);
    }

    public function run(RunAgentsNow $run): JsonResponse
    {
        return response()->json(['data' => $run()]);
    }
}
