<?php

declare(strict_types=1);

namespace App\Application\Portal\Query;

use App\Application\Portal\Service\PortalAccess;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\DTO\ReportSummary;
use App\Application\Shared\Contract\ActorContext;
use App\Domain\Portal\PortalModule;
use App\Domain\Reporting\ReportAudience;

/**
 * The reports this client may read.
 *
 * **Client-safe only, filtered here rather than trusted to the caller.** `ReportAudience::ClientSafe` is applied
 * after the repository returns, because the repository's list is the agency's list and gaining an audience filter
 * would make it one parameter away from being wrong. A report an agency marked internal does not appear on this
 * surface whatever the portal configuration says — the same rule the share link follows, for the same reason.
 */
final readonly class GetPortalReports
{
    /** Enough that a client can find last quarter's, few enough that the page is a list rather than an archive. */
    private const int LIMIT = 50;

    public function __construct(
        private ReportRepository $reports,
        private PortalAccess $access,
        private ActorContext $actor,
    ) {}

    /** @return list<ReportSummary> */
    public function __invoke(string $clientId): array
    {
        $this->access->ensure($clientId, PortalModule::Reports);

        $all = $this->reports->list($this->actor->workspaceId()->value, null, $clientId, self::LIMIT);

        return array_values(array_filter(
            $all,
            static fn (ReportSummary $summary): bool => $summary->audience === ReportAudience::ClientSafe,
        ));
    }
}
