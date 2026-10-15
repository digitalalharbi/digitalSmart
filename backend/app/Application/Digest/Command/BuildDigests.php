<?php

declare(strict_types=1);

namespace App\Application\Digest\Command;

use App\Application\Attention\DTO\AttentionItem;
use App\Application\Attention\Query\GetAttentionQueue;
use App\Application\Digest\Contract\DigestStore;
use App\Application\Shared\Contract\ActorContextBinder;
use App\Application\Shared\Contract\Clock;
use App\Domain\Attention\DigestKind;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;
use Throwable;

/**
 * Write each person one summary instead of N alerts.
 *
 * Every digest is built **as the person it is for**: the actor is bound to them and the attention queue is
 * asked in their name, so what goes in is exactly what their permissions and project scope would show them
 * on the home screen — a digest can never be a side door around visibility.
 *
 * Empty digests are not written. A morning message saying nothing is wrong is the noise this exists to
 * remove.
 */
final readonly class BuildDigests
{
    private const int TOP = 3;

    public function __construct(
        private DigestStore $store,
        private GetAttentionQueue $queue,
        private ActorContextBinder $actors,
        private Clock $clock,
    ) {}

    /** @return array{written: int, skipped: int, failed: int} */
    public function __invoke(DigestKind $kind, ?string $workspaceId = null): array
    {
        $written = $skipped = $failed = 0;
        $today = $this->clock->now();

        foreach ($this->store->recipients($workspaceId) as $recipient) {
            try {
                $this->actors->bind(new UserId($recipient['user_id']), new WorkspaceId($recipient['workspace_id']));

                $queue = ($this->queue)($kind->streams());

                if ($queue->total === 0) {
                    $skipped++;

                    continue;
                }

                $byStream = [];

                foreach ($queue->items as $item) {
                    $byStream[$item->stream->value] = ($byStream[$item->stream->value] ?? 0) + 1;
                }

                $this->store->put(
                    $recipient['workspace_id'],
                    $recipient['user_id'],
                    $kind,
                    $today,
                    $queue->total,
                    [
                        'by_severity' => $queue->severityCounts,
                        'by_stream' => $byStream,
                        'top' => array_map(static fn (AttentionItem $i): array => [
                            'message' => $i->message,
                            'severity' => $i->severity->value,
                            'entity' => $i->entityLabel,
                            'client' => $i->clientName,
                        ], array_slice($queue->items, 0, self::TOP)),
                        'covered' => array_map(static fn ($s): string => $s->value, $queue->covered),
                    ],
                );

                $written++;
            } catch (Throwable) {
                /* One person's failure must not stop the rest of the agency getting theirs. */
                $failed++;
            } finally {
                $this->actors->clear();
            }
        }

        return ['written' => $written, 'skipped' => $skipped, 'failed' => $failed];
    }
}
