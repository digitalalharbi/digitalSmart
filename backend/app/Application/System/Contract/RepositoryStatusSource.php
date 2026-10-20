<?php

declare(strict_types=1);

namespace App\Application\System\Contract;

/**
 * What the source repository's own CI says about a branch — read, never decided here.
 *
 * Returns the state of the latest run of the named workflow as one of `green`, `amber`, `red`, `grey`, with the head
 * commit it ran on, or null when nothing could be read (offline, rate limited, no such run). A null is shown as
 * unknown rather than as green: a status panel that cannot see must say so.
 */
interface RepositoryStatusSource
{
    /** @return array{state: string, sha: string|null}|null */
    public function latestRun(string $branch, string $workflow): ?array;
}
