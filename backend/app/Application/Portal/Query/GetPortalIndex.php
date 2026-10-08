<?php

declare(strict_types=1);

namespace App\Application\Portal\Query;

use App\Application\Portal\DTO\PortalEntry;
use App\Application\Portal\Service\PortalAccess;

/**
 * What this reader may open.
 *
 * The portal's own navigation comes from here rather than from a list of surfaces with some of them hidden. A
 * client's interface is **built from what they may read**, which is why there is nothing on it to be refused: a
 * menu item that answers 404 tells a client something exists and is being kept from them, and that is a sentence
 * about the agency's intentions rather than a feature.
 */
final readonly class GetPortalIndex
{
    public function __construct(private PortalAccess $access) {}

    /** @return list<PortalEntry> */
    public function __invoke(): array
    {
        return $this->access->readable();
    }
}
