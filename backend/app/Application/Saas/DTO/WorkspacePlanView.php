<?php

declare(strict_types=1);

namespace App\Application\Saas\DTO;

use DateTimeImmutable;

/**
 * A workspace's plan as its administrators read it: each feature with where it came from, each limit with what is used, and the
 * overrides that changed either.
 */
final readonly class WorkspacePlanView
{
    /**
     * @param  list<array{key: string, enabled: bool, source: string, everywhere: bool}>  $features
     * @param  list<array{key: string, allowed: int|null, used: int|null, source: string, monthly: bool, resets_at: DateTimeImmutable|null, over: bool, refuses: bool}>  $limits
     * @param  list<OverrideRecord>  $overrides
     */
    public function __construct(
        public string $planCode,
        public string $planName,
        public array $features,
        public array $limits,
        public array $overrides,
    ) {}
}
