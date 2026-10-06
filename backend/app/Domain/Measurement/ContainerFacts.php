<?php

declare(strict_types=1);

namespace App\Domain\Measurement;

/**
 * The facts about one tag container's published state.
 *
 * Built from the *live* version, never a workspace. A workspace holds what somebody is working
 * on; the live version is what the site is actually running, and the difference between those two
 * is the single most common reason an agency believes tracking is configured when it is not.
 */
final readonly class ContainerFacts
{
    /**
     * @param  list<TagFacts>  $tags  the published tags
     * @param  list<string>  $triggerIds  trigger ids present in the published version
     * @param  list<string>  $measurementIdsFound  every measurement-id-shaped value in any tag parameter
     */
    public function __construct(
        public string $containerId,
        public string $name,
        /** The `GTM-XXXXXX` id, which is how a person recognises the container on a site. */
        public string $publicId,
        public bool $hasLiveVersion,
        public ?string $liveVersionId = null,
        public bool $hasUnpublishedChanges = false,
        public array $tags = [],
        public array $triggerIds = [],
        public array $measurementIdsFound = [],
    ) {}
}
