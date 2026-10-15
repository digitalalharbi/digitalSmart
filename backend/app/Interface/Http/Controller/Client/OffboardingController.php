<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Client;

use App\Application\Client\Command\OffboardClient;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OffboardingController extends Controller
{
    public function preview(string $client, OffboardClient $offboard): JsonResponse
    {
        return response()->json(['data' => $offboard->preview($client)]);
    }

    public function offboard(Request $request, string $client, OffboardClient $offboard): JsonResponse
    {
        $validated = $request->validate([
            /* A reason is required: this is the one act in the product that is hard to walk back from. */
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return response()->json(['data' => $offboard($client, (string) $validated['reason'])]);
    }
}
