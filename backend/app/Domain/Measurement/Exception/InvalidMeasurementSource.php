<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Exception;

use App\Domain\Shared\Exception\DomainException;

final class InvalidMeasurementSource extends DomainException
{
    public function errorCode(): string
    {
        return 'invalid_measurement_source';
    }

    public static function alreadyRegistered(string $externalId): self
    {
        return new self(sprintf(
            'This client already measures through "%s". Two records for one property would double every count read through it.',
            $externalId,
        ));
    }

    public static function kindNeedsAProvider(string $kind): self
    {
        return new self(sprintf(
            'A %s source is read through a platform, so it needs one named.',
            strtolower($kind),
        ));
    }

    public static function providerIsWrongKind(string $provider, string $kind): self
    {
        return new self(sprintf(
            '%s is not a %s provider, so it cannot measure as one.',
            $provider,
            strtolower($kind),
        ));
    }
}
