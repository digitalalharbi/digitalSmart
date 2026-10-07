<?php

declare(strict_types=1);

namespace App\Application\Creative\Command;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Creative\Contract\CreativeRepository;
use App\Application\Creative\DTO\CommentInput;
use App\Application\Creative\DTO\CreativeRequestView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Access\Permission;

/**
 * Leave a comment, internal or client-facing.
 *
 * Gated on `creative.view` rather than `creative.manage`: a media buyer who can read the work has something
 * useful to say about it, and requiring the production permission to leave a note would push the
 * conversation into a chat nobody can find later.
 *
 * Not audited. A comment **is** its own record — it carries its author, its time and its audience — and an
 * audit entry duplicating it would be a second copy to keep consistent for no gain.
 */
final readonly class CommentOnRequest
{
    public function __construct(
        private CreativeRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    public function __invoke(string $requestId, CommentInput $input): CreativeRequestView
    {
        $this->guard->ensure(Permission::CreativeView);

        $workspaceId = $this->actor->workspaceId()->value;

        if ($this->requests->find($workspaceId, $this->visibility->visibleProjectIds(), $requestId) === null) {
            throw ResourceNotFound::of('Creative request');
        }

        return $this->requests->comment($workspaceId, $requestId, $input, $this->actor->userId()->value);
    }
}
