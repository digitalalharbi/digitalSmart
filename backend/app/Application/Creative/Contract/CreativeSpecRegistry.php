<?php

declare(strict_types=1);

namespace App\Application\Creative\Contract;

use App\Domain\Creative\AssetKind;
use App\Domain\Creative\CreativeSpec;

interface CreativeSpecRegistry
{
    /**
     * The recorded specification for one placement, or null.
     *
     * Null is the commonest answer and is not a failure: the platforms do not publish their creative
     * requirements in a form a program can read, so most placements have none recorded. A caller that
     * treated null as "no constraints" would report every asset as valid, which is why `SpecReport` has a
     * `noSpec` constructor and `SpecVerdict` has a `NoSpec` member.
     */
    public function find(string $provider, string $placement, AssetKind $kind): ?CreativeSpec;

    /**
     * Everything recorded, for the page that tells an agency which placements are covered.
     *
     * @return list<CreativeSpec>
     */
    public function all(): array;
}
