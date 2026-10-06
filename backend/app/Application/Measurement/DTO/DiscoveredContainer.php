<?php

declare(strict_types=1);

namespace App\Application\Measurement\DTO;

/**
 * One tag container the connection can read, as the provider describes it.
 *
 * `publicId` is the field that matters to a person: `GTM-XXXXXX` is what they will find in a
 * site's source, and it is the only way to be sure a container is the one on their client's
 * website. A numeric container id proves nothing to anybody.
 */
final readonly class DiscoveredContainer
{
    /**
     * @param  list<string>  $usageContexts  `web`, `android`, `ios`, `amp`, `server`, …
     * @param  list<string>  $domainNames
     * @param  list<string>  $tagIds  Google tag ids configured on the container itself
     */
    public function __construct(
        public string $containerId,
        /** `accounts/{account}/containers/{container}` — what later requests are addressed to. */
        public string $path,
        public string $name,
        public string $publicId,
        public string $accountId,
        public array $usageContexts = [],
        public array $domainNames = [],
        public array $tagIds = [],
        public ?string $tagManagerUrl = null,
    ) {}

    /**
     * Whether this container runs on a website.
     *
     * Worth distinguishing before anybody expects web events from it: a server-side or app
     * container measures something real and measures it somewhere else.
     */
    public function isWeb(): bool
    {
        return in_array('web', $this->usageContexts, true);
    }
}
