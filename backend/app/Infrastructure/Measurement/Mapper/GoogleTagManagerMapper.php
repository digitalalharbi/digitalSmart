<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement\Mapper;

use App\Application\Measurement\DTO\DiscoveredContainer;
use App\Domain\Measurement\ContainerFacts;
use App\Domain\Measurement\TagFacts;

/**
 * Tag Manager API v2 responses to normalised records.
 *
 * Documented shapes, mapped here so the mapping is testable without transport:
 *
 *  - `GET https://tagmanager.googleapis.com/tagmanager/v2/accounts` → `account[]`
 *  - `GET …/v2/{parent}/containers` → `container[]`, each with `containerId`, `name`, `publicId`,
 *    `usageContext[]`, `domainName[]`, `tagIds[]`, `path`, `tagManagerUrl`
 *  - `GET …/v2/{parent}/versions:live` → one `ContainerVersion` with `containerVersionId`, `tag[]`,
 *    `trigger[]`, `variable[]`
 *  - `GET …/v2/{path}/status` → `workspaceChange[]`
 *
 * **The measurement-id scan is the one piece of judgement here, and it is narrow on purpose.**
 * A tag's parameters are a tree of `{type, key, value, list[], map[]}`, and which key holds a
 * measurement id depends on the tag template — `gaawe`, `googtag` and a hand-written custom
 * template all differ, and this product does not claim to know the internal parameter schema of
 * every template in existence. So the scan looks for the *id's own shape* (`G-XXXXXXX`,
 * `GT-XXXXXXX`, `AW-…`, `UA-…`) anywhere in the tree, under any key, at any depth. Finding the id
 * is a fact about the container. Assuming a key would be a guess, and assuming a tag's purpose
 * from its name would be worse.
 */
final readonly class GoogleTagManagerMapper
{
    /**
     * Google tag and measurement id shapes.
     *
     * `G-` is a GA4 measurement id, `GT-` a Google tag id, `AW-` Google Ads conversion, `UA-` the
     * retired Universal Analytics property id — included because a container still carrying one is
     * worth a person seeing.
     */
    private const string ID_PATTERN = '/\b(?:G|GT|AW|UA)-[A-Z0-9]+(?:-[A-Z0-9]+)?\b/i';

    /**
     * Containers from one `containers.list` response.
     *
     * @param  array<string, mixed>  $response
     * @return list<DiscoveredContainer>
     */
    public function containers(array $response): array
    {
        $containers = [];

        foreach ($this->listOf($response, 'container') as $row) {
            $containerId = $this->string($row, 'containerId');
            $path = $this->string($row, 'path');

            if ($containerId === null || $path === null) {
                // Without both there is nothing to address a later request to.
                continue;
            }

            $containers[] = new DiscoveredContainer(
                containerId: $containerId,
                path: $path,
                name: $this->string($row, 'name') ?? $containerId,
                // `GTM-XXXXXX`: the only id a person will recognise from a site's source.
                publicId: $this->string($row, 'publicId') ?? '',
                accountId: $this->string($row, 'accountId') ?? '',
                usageContexts: $this->strings($row, 'usageContext'),
                domainNames: $this->strings($row, 'domainName'),
                tagIds: $this->strings($row, 'tagIds'),
                tagManagerUrl: $this->string($row, 'tagManagerUrl'),
            );
        }

        return $containers;
    }

    /**
     * A container's published state, from its live version.
     *
     * `$version` is null when the container has never been published — which the API reports as a
     * 404 on `versions:live`, and which is the single most consequential thing this whole area can
     * tell somebody.
     *
     * @param  array<string, mixed>|null  $version  a `ContainerVersion`
     */
    public function facts(
        DiscoveredContainer $container,
        ?array $version,
        bool $hasUnpublishedChanges = false,
    ): ContainerFacts {
        if ($version === null) {
            return new ContainerFacts(
                containerId: $container->containerId,
                name: $container->name,
                publicId: $container->publicId,
                hasLiveVersion: false,
                hasUnpublishedChanges: $hasUnpublishedChanges,
            );
        }

        $tags = [];
        $measurementIds = [];

        foreach ($this->listOf($version, 'tag') as $row) {
            $tags[] = $this->tag($row);

            foreach ($this->idsIn($row['parameter'] ?? null) as $id) {
                $measurementIds[strtoupper($id)] = $id;
            }
        }

        /*
         * The container's own `tagIds` count as found too. A Google tag configured at container
         * level rather than through an explicit tag is still that property being loaded, and
         * reporting it as missing would be a false alarm about a working setup.
         */
        foreach ($container->tagIds as $id) {
            if (preg_match(self::ID_PATTERN, $id) === 1) {
                $measurementIds[strtoupper($id)] = $id;
            }
        }

        $triggerIds = [];

        foreach ($this->listOf($version, 'trigger') as $row) {
            $id = $this->string($row, 'triggerId');

            if ($id !== null) {
                $triggerIds[] = $id;
            }
        }

        return new ContainerFacts(
            containerId: $container->containerId,
            name: $container->name,
            publicId: $container->publicId,
            hasLiveVersion: true,
            liveVersionId: $this->string($version, 'containerVersionId'),
            hasUnpublishedChanges: $hasUnpublishedChanges,
            tags: $tags,
            triggerIds: $triggerIds,
            measurementIdsFound: array_values($measurementIds),
        );
    }

    /**
     * Whether a `workspaces.getStatus` response reports anything unpublished.
     *
     * @param  array<string, mixed>  $status
     */
    public function hasChanges(array $status): bool
    {
        return $this->listOf($status, 'workspaceChange') !== [];
    }

    /** @param array<string, mixed> $row */
    private function tag(array $row): TagFacts
    {
        return new TagFacts(
            tagId: $this->string($row, 'tagId') ?? '',
            /*
             * Carried so a finding can name the tag a person has to go and look at. Never read for
             * meaning: "GA4 – Purchase" is a label somebody typed, and it may fire anywhere or
             * nowhere.
             */
            name: $this->string($row, 'name') ?? 'Unnamed tag',
            type: $this->string($row, 'type') ?? '',
            // Absent means not paused, which is the API's own convention for a false boolean.
            isPaused: (bool) ($row['paused'] ?? false),
            firingTriggerIds: $this->strings($row, 'firingTriggerId'),
        );
    }

    /**
     * Every measurement-id-shaped value in a parameter tree.
     *
     * Walks `list[]` and `map[]` recursively, because a Google tag's ids routinely sit two levels
     * down inside a map of settings rather than on a top-level `value`.
     *
     * @return list<string>
     */
    private function idsIn(mixed $parameters, int $depth = 0): array
    {
        // A tree this deep is malformed or hostile; either way it is not worth recursing into.
        if ($depth > 8 || ! is_array($parameters)) {
            return [];
        }

        $ids = [];

        foreach ($parameters as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $value = $parameter['value'] ?? null;

            if (is_string($value) && preg_match_all(self::ID_PATTERN, $value, $matches) > 0) {
                foreach ($matches[0] as $match) {
                    $ids[] = $match;
                }
            }

            foreach (['list', 'map'] as $nested) {
                foreach ($this->idsIn($parameter[$nested] ?? null, $depth + 1) as $id) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function listOf(array $row, string $key): array
    {
        $values = $row[$key] ?? null;
        $list = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $list[] = $value;
            }
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function strings(array $row, string $key): array
    {
        $values = $row[$key] ?? null;
        $strings = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
