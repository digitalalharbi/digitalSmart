<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

/**
 * One data stream on a property: a website, an Android app or an iOS app.
 *
 * Carried through discovery because the measurement id (`G-XXXXXXX`) and the default URI are
 * what let a person recognise the property as their client's. A list of numeric property ids
 * is unusable for the one decision this screen exists to support.
 */
final readonly class DataStreamRecord
{
    public function __construct(
        public string $streamId,
        public string $displayName,
        /** `WEB_DATA_STREAM`, `ANDROID_APP_DATA_STREAM`, `IOS_APP_DATA_STREAM`. */
        public string $type,
        /** Web only: the `G-` measurement id. */
        public ?string $measurementId = null,
        /** Web only: the site the stream is for. The most recognisable field here. */
        public ?string $defaultUri = null,
        /** Apps only: the package name or bundle id. */
        public ?string $appIdentifier = null,
    ) {}

    public function isWeb(): bool
    {
        return $this->type === 'WEB_DATA_STREAM';
    }
}
