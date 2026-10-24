<?php

declare(strict_types=1);

namespace App\Domain\Integration\Capability;

use App\Domain\Integration\Provider;

/** One cell of the registry: the state, and the evidence for it. */
final readonly class CapabilityEvidence
{
    public function __construct(
        public Provider $provider,
        public CapabilityKey $key,
        public CapabilityState $state,
        /** The official page read. Null when nothing was read — which is why the state is unverified. */
        public ?string $reference,
        public ?string $apiVersion,
        /** Y-m-d. */
        public ?string $verifiedOn,
        public ?string $note,
    ) {}
}
