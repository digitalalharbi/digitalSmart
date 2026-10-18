<?php

declare(strict_types=1);

namespace App\Application\Agents\Query;

use App\Application\Agents\Contract\OperatingRuleRepository;
use App\Application\Agents\DTO\OperatingRules;
use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/** The rules in force, for a reader who may see the project — with what that scope itself has set, for the editor. */
final readonly class GetOperatingRules
{
    public function __construct(
        private ResolveOperatingRules $resolve,
        private OperatingRuleRepository $rules,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return array{effective: OperatingRules, own: array<string, mixed>|null} */
    public function __invoke(?string $clientId, ?string $projectId): array
    {
        $this->guard->ensure(Permission::AnalyticsView);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($projectId !== null) {
            $visible = $this->visibility->visibleProjectIds();

            if (($visible !== null && ! in_array($projectId, $visible, true)) || $this->rules->clientOf($workspaceId, $projectId) === null) {
                throw ResourceNotFound::of('Project');
            }

            $clientId = null;
        }

        return [
            'effective' => ($this->resolve)($workspaceId, $clientId, $projectId),
            'own' => $this->rules->own($workspaceId, $clientId, $projectId),
        ];
    }
}
