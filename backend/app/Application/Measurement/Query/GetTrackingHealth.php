<?php

declare(strict_types=1);

namespace App\Application\Measurement\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Measurement\DTO\TrackingHealthView;
use App\Application\Measurement\Service\TrackingHealthComposer;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Domain\Access\Permission;

/**
 * What is wrong with a project's measurement, for the agency.
 *
 * The composition moved to `TrackingHealthComposer` when the client portal needed the same diagnosis under
 * different authorization. What is left here is the part that is specific to an agency reader: the permission,
 * and the visibility their assignments give them.
 *
 * Deliberately *not* merged with the portal's version. The two differ in exactly one respect — who is allowed to
 * ask — and that is the respect worth keeping separate.
 */
final readonly class GetTrackingHealth
{
    public function __construct(
        private TrackingHealthComposer $compose,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(?string $clientId = null, ?string $projectId = null): TrackingHealthView
    {
        $this->guard->ensure(Permission::TrackingView);

        return ($this->compose)(
            $this->actor->workspaceId()->value,
            $this->visibility->visibleProjectIds(),
            $clientId,
            $projectId,
        );
    }
}
