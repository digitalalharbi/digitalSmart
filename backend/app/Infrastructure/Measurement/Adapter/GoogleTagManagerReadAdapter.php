<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement\Adapter;

use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Application\Measurement\Contract\TagContainerReadAdapter;
use App\Application\Measurement\DTO\DiscoveredContainer;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Measurement\ContainerFacts;
use App\Infrastructure\Measurement\Mapper\GoogleTagManagerMapper;
use App\Infrastructure\Measurement\Sandbox\MeasurementFixtures;

/**
 * Google Tag Manager, read through the Tag Manager API v2.
 *
 * Built against the published contracts:
 *
 *  - `GET https://tagmanager.googleapis.com/tagmanager/v2/accounts`
 *  - `GET …/v2/accounts/{account}/containers`
 *  - `GET …/v2/accounts/{account}/containers/{container}/versions:live`
 *  - `GET …/v2/accounts/{account}/containers/{container}/workspaces/{workspace}/status`
 *
 * Read access needs `https://www.googleapis.com/auth/tagmanager.readonly`.
 *
 * **Read-only, permanently, and this one matters more than most.** The API can create, edit and
 * publish tags. A reporting tool that could rewrite a client's measurement setup is a tool that
 * will eventually break one at three in the afternoon with nobody able to say what changed. The
 * registry records no write capability, and there is no code here that would use one.
 *
 * **It reads the live version, never a workspace.** A workspace holds what somebody is working on.
 * The live version is what the site is actually running. An agency believing tracking is
 * configured because the workspace looks right is the single most common failure in this area, and
 * reading the wrong one would make this product a participant in it.
 *
 * BLOCKED_EXTERNAL: same gate as Analytics — a Google Cloud project with the API enabled, an
 * allow-listed OAuth redirect URI, and a user who can read the container.
 */
final readonly class GoogleTagManagerReadAdapter implements TagContainerReadAdapter
{
    private const string FIXTURE_CONTAINERS = 'containers';

    private const string FIXTURE_LIVE_VERSION = 'versions_live';

    private const string FIXTURE_STATUS = 'workspace_status';

    public function __construct(
        private MeasurementFixtures $fixtures,
        private GoogleTagManagerMapper $mapper,
    ) {}

    public function provider(): Provider
    {
        return Provider::GoogleTagManager;
    }

    /** @return list<DiscoveredContainer> */
    public function discoverContainers(Credentials $credentials): array
    {
        $this->assertConfigured($credentials);

        return $this->mapper->containers($this->fixtures->first($this->provider(), self::FIXTURE_CONTAINERS));
    }

    public function readPublishedState(Credentials $credentials, DiscoveredContainer $container): ContainerFacts
    {
        $this->assertConfigured($credentials);

        return $this->mapper->facts(
            $container,
            $this->liveVersion($container->path),
            $this->hasUnpublishedChanges($container->path),
        );
    }

    /**
     * The live version, or null when the container has never been published.
     *
     * @return array<string, mixed>|null
     */
    private function liveVersion(string $containerPath): ?array
    {
        foreach ($this->fixtures->all($this->provider(), self::FIXTURE_LIVE_VERSION) as $version) {
            $path = $version['container']['path'] ?? null;

            if (is_string($path) && $path === $containerPath) {
                return $version;
            }
        }

        /*
         * The provider answers 404 on `versions:live` for a container nobody has published. Null
         * carries that through as a fact rather than an exception, because it is the single most
         * consequential thing this whole area can tell somebody: the workspace looks complete and
         * none of it is on the site.
         */
        return null;
    }

    /**
     * Whether any workspace of this container has unpublished edits.
     *
     * `workspaces.getStatus` is addressed per **workspace** —
     * `accounts/{a}/containers/{c}/workspaces/{w}/status` — and a container can have several, which
     * is how two people work on it at once. So this matches on the container prefix and treats
     * changes in any workspace as unpublished: from the live site's point of view that is exactly
     * what they are, regardless of whose workspace they sit in.
     */
    private function hasUnpublishedChanges(string $containerPath): bool
    {
        foreach ($this->fixtures->all($this->provider(), self::FIXTURE_STATUS) as $status) {
            $path = $status['path'] ?? null;

            if (is_string($path) && str_starts_with($path, $containerPath.'/workspaces/')
                && $this->mapper->hasChanges($status)) {
                return true;
            }
        }

        /*
         * Nothing recorded means nothing known to be unpublished. False rather than a guess:
         * claiming unpublished changes that do not exist sends somebody to publish a container for
         * no reason, and the next warning they see carries less weight.
         */
        return false;
    }

    /**
     * @throws ProviderNotConfigured when credentials are missing, or real and therefore unusable
     */
    private function assertConfigured(Credentials $credentials): void
    {
        $required = $this->provider()->requiredCredentialKeys();
        $missing = $credentials->missing($required);

        if ($missing !== []) {
            throw ProviderNotConfigured::for($this->provider(), $missing);
        }

        if (! $credentials->isSandbox($required)) {
            throw ProviderNotConfigured::awaitingProviderApproval($this->provider());
        }
    }
}
