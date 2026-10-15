<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\ReadModel;

use App\Application\Benchmark\Contract\ClientIndustryDirectory;
use Illuminate\Support\Facades\DB;

final readonly class EloquentClientIndustryDirectory implements ClientIndustryDirectory
{
    public function industries(string $workspaceId): array
    {
        $out = [];

        foreach (DB::table('clients')->where('workspace_id', $workspaceId)->get(['id', 'industry']) as $c) {
            $out[(string) $c->id] = is_string($c->industry) && $c->industry !== '' ? $c->industry : 'Unspecified';
        }

        return $out;
    }
}
