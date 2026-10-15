<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Benchmark;

use App\Application\Benchmark\Query\GetBenchmarks;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;

final class BenchmarkController extends Controller
{
    public function show(GetBenchmarks $benchmarks): JsonResponse
    {
        return response()->json(['data' => $benchmarks()]);
    }
}
