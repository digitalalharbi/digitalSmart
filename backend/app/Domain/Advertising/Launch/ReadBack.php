<?php

declare(strict_types=1);

namespace App\Domain\Advertising\Launch;

/** A created campaign as the platform reports it afterwards, compared with what was planned. */
final readonly class ReadBack
{
    /**
     * @param  array<string, mixed>  $observed  status, budget, currency, schedule, objective, hierarchy as read
     * @param  list<string>  $mismatches
     */
    public function __construct(
        public bool $found,
        public array $observed,
        public array $mismatches,
    ) {}

    public function matches(): bool
    {
        return $this->found && $this->mismatches === [];
    }
}
