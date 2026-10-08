<?php

declare(strict_types=1);

namespace App\Application\Portal\Query;

use App\Application\Measurement\DTO\TrackingHealthView;
use App\Application\Measurement\Service\TrackingHealthComposer;
use App\Application\Portal\Service\PortalAccess;
use App\Application\Shared\Contract\ActorContext;
use App\Domain\Portal\PortalModule;

/**
 * Whether measurement on the client's own site is working.
 *
 * The one module here that reports a **problem** rather than a result, and that is why it is exposable at all: the
 * findings are facts about the client's own property, and a client who cannot see that their purchase event stopped
 * firing three weeks ago is a client who will be shown a conversion figure nobody can explain.
 *
 * It shares `TrackingHealthComposer` with the agency's own surface deliberately. Two diagnoses of the same site
 * would be two answers, and the one the client read would be the one nobody maintained.
 */
final readonly class GetPortalTracking
{
    public function __construct(
        private TrackingHealthComposer $compose,
        private PortalAccess $access,
        private ActorContext $actor,
    ) {}

    public function __invoke(string $clientId): TrackingHealthView
    {
        $this->access->ensure($clientId, PortalModule::Tracking);

        return ($this->compose)(
            $this->actor->workspaceId()->value,
            /*
             * Null, narrowed by the client id below instead. A client reads their own measurement, not the subset
             * a staff member previewing it happens to be assigned to.
             */
            null,
            $clientId,
        );
    }
}
