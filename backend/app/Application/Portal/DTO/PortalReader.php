<?php

declare(strict_types=1);

namespace App\Application\Portal\DTO;

/**
 * Who is reading a client's portal, and on what footing.
 *
 * `isPreview` is the whole reason this exists. An agency user with `portal.view` may open a client's portal to read
 * it as the client would — the same argument the reporting module makes about a client-safe report, that it should
 * be read as they would read it before it is sent. The response says so, because an agency user who mistook a
 * preview for their own view would conclude that half the product had disappeared.
 *
 * A preview reads through **exactly the same path** as the client. There is no agency shaping on it: a preview
 * built from a different query would be a preview of something nobody will see.
 */
final readonly class PortalReader
{
    public function __construct(
        public string $clientId,
        public string $clientName,
        public bool $isPreview,
    ) {}
}
