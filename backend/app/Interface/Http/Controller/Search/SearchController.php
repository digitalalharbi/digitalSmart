<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Search;

use App\Application\Search\DTO\SearchHit;
use App\Application\Search\Query\GlobalSearch;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SearchController extends Controller
{
    public function index(Request $request, GlobalSearch $search): JsonResponse
    {
        $types = array_values(array_filter((array) $request->query('type', []), 'is_string'));

        foreach ($types as $type) {
            if (! in_array($type, GlobalSearch::TYPES, true)) {
                return response()->json([
                    'message' => 'Unknown type.',
                    'code' => 'invalid_type',
                    'allowed' => GlobalSearch::TYPES,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $term = $request->query('q');
        $per = $request->query('per_type');

        $hits = $search(
            is_string($term) ? $term : '',
            $types,
            is_numeric($per) ? (int) $per : 5,
        );

        return response()->json(['data' => array_map(static fn (SearchHit $h): array => [
            'type' => $h->type,
            'id' => $h->id,
            'label' => $h->label,
            'context' => $h->context,
            'path' => $h->path,
        ], $hits)]);
    }
}
