<?php

declare(strict_types=1);

namespace App\Application\Creative\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Creative\Contract\CreativeRepository;
use App\Application\Creative\DTO\CreativeBoardView;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\AuthorizationGuard;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\Permission;

/**
 * The board.
 *
 * Deliberately does no specification checking. A board answers "where is everything" and a check answers
 * "is this file right" — running every version through a spec to draw a column would make the page slow for
 * a figure nobody reads from a card.
 */
final readonly class GetCreativeBoard
{
    /** Enough per column to see the shape of the work without loading a year of it. */
    private const int PER_COLUMN = 50;

    public function __construct(
        private CreativeRepository $requests,
        private AuthorizationGuard $guard,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
        private Clock $clock,
    ) {}

    public function __invoke(?string $clientId = null, ?int $perColumn = null): CreativeBoardView
    {
        $this->guard->ensure(Permission::CreativeView);

        return new CreativeBoardView(
            columns: $this->requests->board(
                $this->actor->workspaceId()->value,
                $this->visibility->visibleProjectIds(),
                $clientId,
                max(1, min($perColumn ?? self::PER_COLUMN, 200)),
            ),
            // Passed in rather than read inside the view, so "overdue" is one moment for the whole board.
            now: $this->clock->now(),
        );
    }
}
