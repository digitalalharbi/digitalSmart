<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Health;

final readonly class HealthCheck
{
    /** @param array<string, scalar|null> $evidence what the state rests on, so a reader can disagree with it */
    public function __construct(
        public string $key,
        public string $group,
        public TrackingHealthState $state,
        public string $detail,
        public array $evidence = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'group' => $this->group,
            'state' => $this->state->value,
            'needs_attention' => $this->state->needsAttention(),
            'detail' => $this->detail,
            'evidence' => (object) $this->evidence,
        ];
    }
}
