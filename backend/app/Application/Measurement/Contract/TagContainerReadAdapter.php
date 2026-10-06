<?php

declare(strict_types=1);

namespace App\Application\Measurement\Contract;

use App\Application\Measurement\DTO\DiscoveredContainer;
use App\Domain\Integration\Credentials;
use App\Domain\Measurement\ContainerFacts;

/**
 * Reading a tag container's configuration.
 *
 * A third adapter shape, and the reason is that a container reports **configuration, not events**.
 * It has no counts to contribute, so it has no place in `MeasurementSyncAdapter`, and forcing it
 * there would mean implementing `fetchEventCounts()` as an empty page — which a reader would
 * reasonably take to mean "measured nothing" rather than "not that kind of thing".
 *
 * Contract:
 *
 *   - Read the **live** version, never a workspace. A workspace is what somebody is working on;
 *     the live version is what the site is running, and confusing the two is the failure this
 *     whole area exists to catch.
 *   - A container with no published version is reported as such, not as an error.
 *   - Return structural facts only. No implementation may infer a tag's purpose from its name.
 */
interface TagContainerReadAdapter
{
    /**
     * Every container the connection can read.
     *
     * @return list<DiscoveredContainer>
     */
    public function discoverContainers(Credentials $credentials): array;

    /**
     * One container's published state, already normalised.
     *
     * The adapter owns the mapping as well as the transport, as the advertising adapters do: the
     * provider's shapes stop at this boundary, and a use case that had to walk a `ContainerVersion`
     * would be a use case that knows Tag Manager's schema.
     *
     * A container with no published version comes back with `hasLiveVersion` false rather than as
     * an error — the provider answers 404 on `versions:live`, and that is a fact about the client's
     * setup, the most consequential one this adapter can report.
     */
    public function readPublishedState(Credentials $credentials, DiscoveredContainer $container): ContainerFacts;
}
