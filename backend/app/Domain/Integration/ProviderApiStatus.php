<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * How reachable a provider's API is *in principle* — not whether this workspace has
 * connected it.
 *
 * The distinction matters because the two failures need different answers. A workspace
 * that has not connected Meta needs to click a button; a workspace that wants ChatGPT
 * Ads needs the programme to exist. Rendering both as "not connected" sends somebody
 * looking for a setting that cannot help them.
 */
enum ProviderApiStatus: string
{
    /** Published, documented, and open to any developer account. */
    case GenerallyAvailable = 'generally_available';

    /** Published, but access is granted per application by the provider. */
    case RequiresApproval = 'requires_approval';

    /** No public API, or no programme to apply to. Nothing here can be built yet. */
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::GenerallyAvailable => 'Generally available',
            self::RequiresApproval => 'Requires provider approval',
            self::Unavailable => 'No public API yet',
        };
    }

    /** Whether a workspace can get there on its own. */
    public function isSelfService(): bool
    {
        return $this === self::GenerallyAvailable;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
