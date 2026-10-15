<?php

declare(strict_types=1);

namespace App\Application\Shortcuts\Query;

use App\Application\Analytics\Contract\VisibilityResolver;
use App\Application\Search\Contract\SearchReadModel;
use App\Application\Search\DTO\SearchHit;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shortcuts\Contract\ShortcutStore;

/**
 * What this person pinned, and what they last looked at — resolved against what they may still see.
 *
 * A shortcut is a stored pointer; whether it still means anything is decided on every read. A project the
 * person has left, a client that was deleted: both simply fall out, which is the safe direction to fail.
 */
final readonly class GetShortcuts
{
    public function __construct(
        private ShortcutStore $store,
        private SearchReadModel $search,
        private ActorContext $actor,
        private VisibilityResolver $visibility,
    ) {}

    /** @return array{pinned: list<SearchHit>, recent: list<SearchHit>} */
    public function __invoke(int $recentLimit = 10): array
    {
        if (! $this->actor->isBound()) {
            return ['pinned' => [], 'recent' => []];
        }

        $workspace = $this->actor->workspaceId()->value;
        $user = $this->actor->userId()->value;

        $pinned = $this->store->pinned($workspace, $user);
        $recent = $this->store->recent($workspace, $user, max(1, min($recentLimit, 20)));

        /* One lookup for both lists, then split back in stored order. */
        $hits = $this->search->lookup($workspace, $this->visibility->visibleProjectIds(), [...$pinned, ...$recent]);
        $byKey = [];

        foreach ($hits as $hit) {
            $byKey[$hit->type.':'.$hit->id] = $hit;
        }

        $resolve = static fn (array $list): array => array_values(array_filter(array_map(
            static fn (array $e): ?SearchHit => $byKey[$e['type'].':'.$e['id']] ?? null,
            $list,
        )));

        return ['pinned' => $resolve($pinned), 'recent' => $resolve($recent)];
    }
}
