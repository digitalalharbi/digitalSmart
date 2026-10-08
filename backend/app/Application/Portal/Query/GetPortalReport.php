<?php

declare(strict_types=1);

namespace App\Application\Portal\Query;

use App\Application\Portal\Service\PortalAccess;
use App\Application\Reporting\Contract\ReportRepository;
use App\Application\Reporting\DTO\RenderedReport;
use App\Application\Reporting\Query\RenderReport;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Portal\PortalModule;
use App\Domain\Reporting\ReportAudience;

/**
 * One report, rendered for the client.
 *
 * Three locks, each of which would be sufficient on its own and none of which is therefore redundant:
 *
 *  - The report must **belong to this client**, so a report id guessed from another portal reaches nothing.
 *  - The report must be marked **client-safe**. An internal one answers 404, not 403: a client being told a
 *    report about them exists and is being withheld is a sentence about the agency's intentions.
 *  - The render is requested as `ClientSafe` **explicitly**, so the agency's commentary is never loaded on this
 *    path rather than being loaded and then dropped.
 *
 * The third is the one that matters most, and it is the same construction `ReadSharedReport` uses: the internal
 * note is not written, not written-and-hidden.
 */
final readonly class GetPortalReport
{
    public function __construct(
        private ReportRepository $reports,
        private RenderReport $render,
        private PortalAccess $access,
        private ActorContext $actor,
    ) {}

    public function __invoke(string $clientId, string $reportId): RenderedReport
    {
        $this->access->ensure($clientId, PortalModule::Reports);

        $workspaceId = $this->actor->workspaceId()->value;

        $report = $this->reports->find($workspaceId, null, $reportId)
            ?? throw ResourceNotFound::of('Report');

        if ($report->definition->clientId !== $clientId) {
            throw ResourceNotFound::of('Report');
        }

        if ($report->definition->audience !== ReportAudience::ClientSafe) {
            throw ResourceNotFound::of('Report');
        }

        return ($this->render)($workspaceId, $report, ReportAudience::ClientSafe);
    }
}
