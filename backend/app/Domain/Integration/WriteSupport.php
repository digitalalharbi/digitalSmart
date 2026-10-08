<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * How well this product knows that a provider supports a write.
 *
 * The enum this phase exists for. Every other integration in this product is a read, and a read that is wrong is
 * a figure somebody questions. A write that is wrong is a client's campaign in a state nobody chose — so the
 * question "can we actually do this" is answered in three levels rather than one boolean, and only the strongest
 * one permits an attempt.
 *
 * **`Documented`** means the operation was read in the provider's **own current documentation**, and the endpoint
 * and field names recorded alongside it. {@see Provider::writeReference()} carries what was read.
 *
 * **`Unverified`** is the level that earns this enum its keep. Several providers' write endpoints are widely
 * implemented and described in third-party SDKs and wrappers — and a third-party SDK is not the provider's
 * documentation. Two of this product's six advertising platforms are here because their own documentation could
 * not be read: TikTok publishes it through a portal that serves no readable content to a fetcher, and X's
 * developer documentation was not reachable. The capability is **not** claimed on somebody else's say-so, and a
 * write is refused with that sentence rather than attempted hopefully.
 *
 * **`NotSupported`** means the provider does not offer it, or this product has decided not to use it.
 */
enum WriteSupport: string
{
    case Documented = 'documented';

    case Unverified = 'unverified';

    case NotSupported = 'not_supported';

    public function label(): string
    {
        return match ($this) {
            self::Documented => 'Documented by the provider',
            self::Unverified => 'Not verified against the provider\'s documentation',
            self::NotSupported => 'Not supported',
        };
    }

    /**
     * Whether this product will attempt the write.
     *
     * Only for `Documented`. An attempt on an unverified endpoint is a guess made against a client's live
     * campaign, and the failure mode is silent: a request that returns 200 having changed something else.
     */
    public function allowsAttempt(): bool
    {
        return $this === self::Documented;
    }

    /** Why not, in a sentence an interface can show. Null once an attempt is allowed. */
    public function because(): ?string
    {
        return match ($this) {
            self::Documented => null,
            self::Unverified => 'This product has not been able to read this operation in the provider\'s own '
                .'current documentation, so it will not attempt it. Third-party libraries describe the endpoint; '
                .'that is not the same thing, and a guess here is a guess against a client\'s live campaign. '
                .'Make the change in the platform and record it here.',
            self::NotSupported => 'The provider does not offer this through its API. Make the change in the '
                .'platform and record it here.',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
