<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Advertising\MetricSource;

/**
 * What happened when this product asked a platform to change something.
 *
 * **Confirmation is a read, not an acknowledgement.** The provider returning 200 means it accepted the request;
 * it does not mean the campaign is paused. So a confirmed outcome carries `confirmedState` — what the provider
 * reported when the entity was **read back** — and a write that could not be read back is not confirmed,
 * whatever the response code said.
 *
 * `source` is on every outcome for the same reason it is on every figure in this product. A write performed
 * against sandbox credentials is a `SANDBOX` outcome and says so everywhere it appears, because an audit trail
 * recording a simulated pause as a real one is worse than no audit trail.
 */
final readonly class WriteOutcome
{
    private function __construct(
        public bool $isConfirmed,
        public WriteOperation $operation,
        public Provider $provider,
        /** What the provider reported when the entity was read back. Null when nothing was confirmed. */
        public ?string $confirmedState,
        public ?WriteFailure $failure,
        /** The provider's own message, verbatim and unparsed. */
        public ?string $providerMessage,
        /** The provider's request identifier, where it gives one. The only thing their support can act on. */
        public ?string $providerRequestId,
        public MetricSource $source,
    ) {}

    public static function confirmed(
        WriteOperation $operation,
        Provider $provider,
        string $confirmedState,
        MetricSource $source,
        ?string $providerRequestId = null,
    ): self {
        return new self(
            isConfirmed: true,
            operation: $operation,
            provider: $provider,
            confirmedState: $confirmedState,
            failure: null,
            providerMessage: null,
            providerRequestId: $providerRequestId,
            source: $source,
        );
    }

    public static function failed(
        WriteOperation $operation,
        Provider $provider,
        WriteFailure $failure,
        ?string $providerMessage = null,
        ?string $providerRequestId = null,
        MetricSource $source = MetricSource::Live,
    ): self {
        return new self(
            isConfirmed: false,
            operation: $operation,
            provider: $provider,
            confirmedState: null,
            failure: $failure,
            providerMessage: $providerMessage,
            providerRequestId: $providerRequestId,
            source: $source,
        );
    }

    /** What a person reads. The provider's own words are appended rather than interpreted. */
    public function summary(): string
    {
        if ($this->isConfirmed) {
            return sprintf(
                '%s reported "%s" when the campaign was read back.',
                $this->provider->label(),
                $this->confirmedState ?? '',
            );
        }

        $failure = $this->failure ?? WriteFailure::Unknown;

        return $this->providerMessage === null
            ? $failure->label()
            : sprintf('%s — %s said: %s', $failure->label(), $this->provider->label(), $this->providerMessage);
    }
}
