<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Agents;

use App\Application\Intelligence\Command\SaveModelCredentials;
use App\Application\Intelligence\Query\GetModelRouting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ModelRoutingController
{
    public function show(GetModelRouting $routing): JsonResponse
    {
        return new JsonResponse(['data' => $routing()]);
    }

    public function update(Request $request, SaveModelCredentials $save): JsonResponse
    {
        /** @var array{api_key?: string|null, strong_model?: string|null, fast_model?: string|null, remove?: bool} $input */
        $input = $request->validate([
            'api_key' => ['sometimes', 'nullable', 'string', 'min:20', 'max:300'],
            'strong_model' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9.\-]+$/'],
            'fast_model' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9.\-]+$/'],
            'remove' => ['sometimes', 'boolean'],
        ]);

        return new JsonResponse(['data' => $save(
            $input['api_key'] ?? null,
            $input['strong_model'] ?? null,
            $input['fast_model'] ?? null,
            (bool) ($input['remove'] ?? false),
        )]);
    }
}
