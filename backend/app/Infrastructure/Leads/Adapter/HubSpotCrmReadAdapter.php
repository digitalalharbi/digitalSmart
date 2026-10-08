<?php

declare(strict_types=1);

namespace App\Infrastructure\Leads\Adapter;

use App\Application\Integration\Exception\ProviderNotConfigured;
use App\Application\Leads\Contract\CrmReadAdapter;
use App\Application\Leads\DTO\CrmDeal;
use App\Application\Leads\DTO\CrmPipeline;
use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Infrastructure\Leads\Mapper\HubSpotCrmMapper;
use App\Infrastructure\Measurement\Sandbox\MeasurementFixtures;
use DateTimeImmutable;

/**
 * HubSpot, against documented-shape fixtures.
 *
 * **BLOCKED_EXTERNAL.** No HubSpot app exists for this repository, so nothing here makes an HTTP request: given
 * sandbox credentials it reads fixtures shaped exactly like the published responses, and given real ones it
 * refuses rather than pretending. The endpoints, the field names and the string-typed properties are recorded in
 * `HubSpotAdapter` with the date they were read, and the mapper is the same code a live response would go
 * through — which is the only reason a fixture is worth having.
 *
 * The fixture loader is the response-shaped one written for measurement. Its name is narrower than its job: what
 * it does is page whole JSON responses and shift their dates, which is exactly what a CRM read needs, and a
 * fourth copy of date-shifting would be the third place that logic could drift.
 *
 * Pipelines are read first and deals second, and the order is load-bearing. A deal's `dealstage` is an internal
 * id whose meaning lives on the stage, so a sync that read deals without pipelines would hold ids it could only
 * interpret by their labels — the one thing this bridge exists not to do.
 */
final readonly class HubSpotCrmReadAdapter implements CrmReadAdapter
{
    private const string FIXTURE_PIPELINES = 'pipelines_deals';

    private const string FIXTURE_DEALS = 'deals';

    public function __construct(
        private MeasurementFixtures $fixtures,
        private HubSpotCrmMapper $mapper,
    ) {}

    public function provider(): Provider
    {
        return Provider::HubSpot;
    }

    /** @return list<CrmPipeline> */
    public function pipelines(Credentials $credentials): array
    {
        $this->assertSandbox($credentials);

        return $this->mapper->pipelines($this->fixtures->first($this->provider(), self::FIXTURE_PIPELINES));
    }

    /** @return list<CrmDeal> */
    public function deals(Credentials $credentials, DateTimeImmutable $since): array
    {
        $this->assertSandbox($credentials);

        $deals = [];
        $cursor = null;

        do {
            $page = $this->fixtures->page($this->provider(), self::FIXTURE_DEALS, $cursor);

            foreach ($this->mapper->deals($page['response']) as $deal) {
                /*
                 * Filtered here rather than in the request, because that is where a live sync filters too: the
                 * deals list endpoint pages through everything and `search` is a different endpoint with its own
                 * limits. A sync that assumed the list came back already narrowed would silently re-read a year
                 * of deals and call it an incremental run.
                 */
                if ($deal->updatedAt >= $since) {
                    $deals[] = $deal;
                }
            }

            $cursor = $page['nextCursor'];
        } while ($cursor !== null);

        return $deals;
    }

    /**
     * Sandbox credentials, or an honest refusal.
     *
     * Real credentials are refused rather than used, which is the whole of this adapter's honesty: a HubSpot app
     * exists for nobody here, and quietly serving fixtures to a workspace that had configured a real connection
     * would put invented deals in front of a client.
     */
    private function assertSandbox(Credentials $credentials): void
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
