<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Attention;

use App\Application\Attention\Query\GetAttentionQueue;
use App\Domain\Attention\AttentionStream;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Attention\AttentionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything waiting on this person, from every module that already knows.
 *
 * Deliberately not guarded by a permission of its own. Membership is decided per stream, so a reader with
 * none of the six gets an empty queue and an empty `covered` list rather than a 403 — which is the honest
 * answer to "what needs me?" for somebody whose role covers none of it.
 */
final class AttentionController extends Controller
{
    public function index(Request $request, GetAttentionQueue $queue): JsonResponse
    {
        $only = [];

        foreach ((array) $request->query('stream', []) as $value) {
            $stream = is_string($value) ? AttentionStream::tryFrom($value) : null;

            if ($stream === null) {
                return response()->json([
                    'message' => 'Unknown stream.',
                    'code' => 'invalid_stream',
                    'allowed' => AttentionStream::values(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $only[] = $stream;
        }

        $limit = $request->query('limit');

        return response()->json(AttentionResource::queue(
            $queue($only, is_numeric($limit) ? (int) $limit : null),
        ));
    }
}
