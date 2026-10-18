<?php

declare(strict_types=1);

namespace App\Application\Attention\Query;

use App\Application\Attention\Contract\ActorMemberships;
use App\Application\Attention\Contract\AttentionSource;
use App\Application\Attention\DTO\AttentionItem;
use App\Application\Attention\DTO\AttentionQueue;
use App\Application\Shared\Contract\ActorContext;
use App\Application\Shared\Contract\Clock;
use App\Domain\Access\PermissionSet;
use App\Domain\Attention\AttentionAudience;
use App\Domain\Attention\AttentionSeverity;
use App\Domain\Attention\AttentionStream;
use Throwable;

/**
 * One queue, from every module that already knows something is wrong.
 *
 * No new table and no alert engine. Budget pacing, tracking issues, campaign health, approval ages and
 * overdue promises are each computed by the module that owns them; a seventh place computing them again
 * would be free to disagree with the six screens a reader can open to check, and the version on the home
 * screen is the one nobody checks.
 *
 * Three properties hold it together, and two of them are about honesty rather than about the list:
 *
 * **Permission decides membership, not presentation.** Each stream names what it takes to read it and is
 * skipped entirely when the reader lacks it. A finance manager and a creative lead open the same endpoint
 * and the server assembles different lists.
 *
 * **The scope is published.** "Nothing needs attention" means something different to each of them, and an
 * empty list without the streams behind it is a reassurance the product has not earned.
 *
 * **A stream that fails is named.** Otherwise a broken query and a quiet week render identically — and
 * the broken one renders as the reassuring one.
 */
final readonly class GetAttentionQueue
{
    /** @param iterable<AttentionSource> $sources */
    public function __construct(
        private iterable $sources,
        private ActorContext $actor,
        private Clock $clock,
        private ActorMemberships $memberships,
    ) {}

    /**
     * @param  list<AttentionStream>  $only  Restrict to these streams; empty means every stream permitted.
     */
    public function __invoke(array $only = [], ?int $limit = null, AttentionAudience $audience = AttentionAudience::Everything, ?string $projectId = null, ?string $clientId = null): AttentionQueue
    {
        $permissions = $this->actor->isBound()
            ? $this->actor->permissions()
            : null;

        $items = [];
        $covered = [];
        $failed = [];

        foreach ($this->sources as $source) {
            $stream = $source->stream();

            if ($only !== [] && ! in_array($stream, $only, true)) {
                continue;
            }

            if (! $permissions instanceof PermissionSet || ! $this->mayRead($stream, $permissions)) {
                continue;
            }

            $covered[] = $stream;

            try {
                foreach ($source->items() as $item) {
                    $items[] = $item;
                }
            } catch (Throwable) {
                /*
                 * One module failing must not empty the queue. The others are still true, and saying
                 * which one went missing is the difference between a partial answer and a wrong one.
                 */
                $failed[] = $stream;
            }
        }

        $items = $this->narrow($items, $audience);

        if ($projectId !== null || $clientId !== null) {
            /* One project's or one client's own page asks for its own items — not the agency's top two hundred, filtered. */
            $items = array_values(array_filter(
                $items,
                static fn (AttentionItem $item): bool => ($projectId === null || $item->projectId === $projectId)
                    && ($clientId === null || $item->clientId === $clientId),
            ));
        }

        $counts = array_fill_keys(AttentionSeverity::values(), 0);

        foreach ($items as $item) {
            $counts[$item->severity->value]++;
        }

        usort($items, static function (AttentionItem $a, AttentionItem $b): int {
            if ($a->severity !== $b->severity) {
                return $a->severity->rank() <=> $b->severity->rank();
            }

            /*
             * Oldest first inside a severity, and an undated item sorts last rather than first. Unknown
             * age is not old age, and the thing ignored longest is the one most likely to have been
             * forgotten rather than deferred.
             */
            $left = $a->since?->getTimestamp() ?? PHP_INT_MAX;
            $right = $b->since?->getTimestamp() ?? PHP_INT_MAX;

            return $left <=> $right;
        });

        $total = count($items);
        $cap = $limit === null ? null : max(1, min($limit, 200));

        return new AttentionQueue(
            items: $cap === null ? $items : array_slice($items, 0, $cap),
            severityCounts: $counts,
            covered: $covered,
            failed: $failed,
            total: $total,
            asOf: $this->clock->now(),
            audience: $audience,
        );
    }

    /**
     * @param  list<AttentionItem>  $items
     * @return list<AttentionItem>
     */
    private function narrow(array $items, AttentionAudience $audience): array
    {
        if ($audience === AttentionAudience::Everything || ! $this->actor->isBound()) {
            return $items;
        }

        $workspaceId = $this->actor->workspaceId()->value;
        $userId = $this->actor->userId()->value;

        if ($audience === AttentionAudience::MyProjects) {
            $mine = array_flip($this->memberships->projectIds($workspaceId, $userId));

            return array_values(array_filter(
                $items,
                static fn (AttentionItem $i): bool => $i->projectId !== null && isset($mine[$i->projectId]),
            ));
        }

        $mine = array_flip($this->memberships->clientIds($workspaceId, $userId));

        return array_values(array_filter(
            $items,
            static fn (AttentionItem $i): bool => $i->clientId !== null && isset($mine[$i->clientId]),
        ));
    }

    /** Every permission the stream names, not any of them. */
    private function mayRead(AttentionStream $stream, PermissionSet $permissions): bool
    {
        return $permissions->hasAll(...$stream->permissions());
    }
}
