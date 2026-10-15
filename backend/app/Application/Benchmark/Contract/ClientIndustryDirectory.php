<?php

declare(strict_types=1);

namespace App\Application\Benchmark\Contract;

interface ClientIndustryDirectory
{
    /** @return array<string, string> client id => industry, "Unspecified" where none is recorded */
    public function industries(string $workspaceId): array;
}
