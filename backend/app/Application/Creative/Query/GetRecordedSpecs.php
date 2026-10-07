<?php

declare(strict_types=1);

namespace App\Application\Creative\Query;

use App\Application\Creative\Contract\CreativeSpecRegistry;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;
use App\Domain\Creative\CreativeSpec;

/**
 * Which placements have a recorded specification, and how old each one is.
 *
 * A page in its own right, not a developer curiosity. An agency needs to know which placements this product
 * can check their work against and which it cannot, because the second list is where their creative goes out
 * unchecked — and the honest answer is that the second list is much longer, because the platforms do not
 * publish their requirements in a form a program can read.
 */
final readonly class GetRecordedSpecs
{
    public function __construct(
        private CreativeSpecRegistry $specs,
        private AuthorizationGuard $guard,
        private Clock $clock,
    ) {}

    /** @return list<array{spec: CreativeSpec, is_stale: bool, age_days: int}> */
    public function __invoke(): array
    {
        $this->guard->ensure(Permission::CreativeView);

        $now = $this->clock->now();
        $rows = [];

        foreach ($this->specs->all() as $spec) {
            $rows[] = [
                'spec' => $spec,
                // Published rather than left to the reader's arithmetic: the age is the point.
                'is_stale' => $spec->provenance->isStale($now),
                'age_days' => $spec->provenance->ageInDays($now),
            ];
        }

        return $rows;
    }
}
