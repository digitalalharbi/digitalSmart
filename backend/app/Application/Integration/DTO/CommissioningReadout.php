<?php

declare(strict_types=1);

namespace App\Application\Integration\DTO;

use App\Domain\Integration\Provider;

/**
 * What one provider could actually be made to do, right now, with the credentials this installation holds.
 *
 * The four evidence levels are the point, and they are not interchangeable:
 *
 *  - `CODE_VERIFIED` — the adapter exists and its tests pass. Nothing has been reached.
 *  - `SANDBOX_VERIFIED` — fixtures were read through the real mapper. Proves the shape, proves nothing about
 *    an account.
 *  - `LIVE_READ_VERIFIED` — a real credential reached the provider and real rows came back.
 *  - `LIVE_WRITE_VERIFIED` — a real mutation was accepted **and read back**.
 *
 * A sandbox run can never produce either live level, whatever it returns, because the fixtures are in this
 * repository. That rule lives in `level()` rather than in the operator's judgement.
 */
final readonly class CommissioningReadout
{
    /**
     * @param  list<string>  $requiredKeys  credential key names, never values
     * @param  list<string>  $findings  what was read, in the order it was read
     * @param  list<string>  $limitations  what this run could not establish, and why
     */
    public function __construct(
        public Provider $provider,
        public bool $isConfigured,
        public bool $isSandbox,
        public array $requiredKeys,
        public ?ProviderHealth $health = null,
        public array $findings = [],
        public array $limitations = [],
        public ?string $failure = null,
    ) {}

    public function level(): string
    {
        if (! $this->isConfigured) {
            return 'CODE_VERIFIED';
        }

        if ($this->isSandbox) {
            /*
             * Whatever came back, it came from this repository. A sandbox run that reported a live level would
             * be the single most expensive lie this product could tell an operator.
             */
            return 'SANDBOX_VERIFIED';
        }

        if ($this->failure !== null || $this->findings === []) {
            return 'FAILED_EXTERNAL';
        }

        /* Reads only. A write level is earned by a mutation that was read back, which this never performs. */
        return 'LIVE_READ_VERIFIED';
    }

    public function status(): string
    {
        if (! $this->isConfigured) {
            return 'NOT_CONFIGURED';
        }

        if ($this->health !== null && ! $this->health->healthy) {
            return $this->isSandbox ? 'AWAITING_CREDENTIALS' : 'FAILED_EXTERNAL';
        }

        return $this->isSandbox ? 'AWAITING_CREDENTIALS' : 'CONNECTED';
    }
}
