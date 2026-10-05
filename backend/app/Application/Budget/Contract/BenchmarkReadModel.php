<?php

declare(strict_types=1);

namespace App\Application\Budget\Contract;

use App\Application\Budget\DTO\BenchmarkQuery;
use App\Application\Budget\DTO\BenchmarkSet;

/**
 * Rates derived from a workspace's own delivery.
 *
 * The only source of forecast rates in this product. There is no industry-benchmark
 * table to fall back to, because a number nobody in this workspace earned is a number
 * nobody can defend to a client.
 */
interface BenchmarkReadModel
{
    public function observe(string $workspaceId, BenchmarkQuery $query): BenchmarkSet;
}
