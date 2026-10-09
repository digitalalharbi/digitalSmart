<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Repository;

use App\Application\Workspace\Contract\AssignedWorkRepository;
use App\Application\Workspace\DTO\HeldWork;
use App\Domain\Creative\CreativeState;
use App\Domain\Identity\UserId;
use App\Domain\Leads\LeadStage;
use App\Domain\Operations\ClientRequestState;
use App\Domain\Operations\TaskState;
use App\Domain\Workspace\WorkspaceId;
use BackedEnum;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The four assignment columns, in one place.
 *
 * **"Open" is each module's own definition, read from its own enum** rather than restated here as a list of
 * strings. A task is open until it is done or cancelled; a client request until it is delivered, declined or
 * withdrawn; a brief until it is approved or cancelled; a lead until it is won, lost or disqualified. Spelling
 * those out again in SQL would be a second copy of four rules, and the copy would be the one that went stale.
 */
final readonly class EloquentAssignedWorkRepository implements AssignedWorkRepository
{
    public function heldBy(WorkspaceId $workspaceId, UserId $userId): HeldWork
    {
        return new HeldWork(
            tasks: $this->tasks($workspaceId, $userId)->count(),
            leads: $this->leads($workspaceId, $userId)->count(),
            clientRequests: $this->clientRequests($workspaceId, $userId)->count(),
            creativeRequests: $this->creativeRequests($workspaceId, $userId)->count(),
        );
    }

    public function release(WorkspaceId $workspaceId, UserId $userId): HeldWork
    {
        return DB::transaction(function () use ($workspaceId, $userId): HeldWork {
            /*
             * Counted by the update itself rather than by a count beforehand. Two statements could disagree if
             * somebody finished a task in between, and the figure a reader is shown has to be what happened.
             */
            return new HeldWork(
                tasks: $this->tasks($workspaceId, $userId)->update(['assignee_id' => null]),
                leads: $this->leads($workspaceId, $userId)->update(['owner_id' => null]),
                clientRequests: $this->clientRequests($workspaceId, $userId)->update(['owner_id' => null]),
                creativeRequests: $this->creativeRequests($workspaceId, $userId)->update(['owner_id' => null]),
            );
        });
    }

    private function tasks(WorkspaceId $workspaceId, UserId $userId): Builder
    {
        return DB::table('tasks')
            ->where('workspace_id', $workspaceId->value)
            ->where('assignee_id', $userId->value)
            ->whereIn('state', self::open(TaskState::cases(), static fn (TaskState $s): bool => $s->isOpen()));
    }

    private function leads(WorkspaceId $workspaceId, UserId $userId): Builder
    {
        return DB::table('leads')
            ->where('workspace_id', $workspaceId->value)
            ->where('owner_id', $userId->value)
            /* A merged-away lead counts nowhere else either — see the merge migration. */
            ->whereNull('merged_into_id')
            ->whereIn('stage', self::open(LeadStage::cases(), static fn (LeadStage $s): bool => ! $s->isClosed()));
    }

    private function clientRequests(WorkspaceId $workspaceId, UserId $userId): Builder
    {
        return DB::table('client_requests')
            ->where('workspace_id', $workspaceId->value)
            ->where('owner_id', $userId->value)
            ->whereIn('state', self::open(
                ClientRequestState::cases(),
                static fn (ClientRequestState $s): bool => $s->isOpen(),
            ));
    }

    private function creativeRequests(WorkspaceId $workspaceId, UserId $userId): Builder
    {
        return DB::table('creative_requests')
            ->where('workspace_id', $workspaceId->value)
            ->where('owner_id', $userId->value)
            ->whereIn('state', self::open(
                CreativeState::cases(),
                static fn (CreativeState $s): bool => $s->isOpen(),
            ));
    }

    /**
     * The values of the states a module considers open, asked of the enum rather than listed here.
     *
     * @template T of \BackedEnum
     *
     * @param  list<T>  $cases
     * @param  callable(T): bool  $isOpen
     * @return list<string>
     */
    private static function open(array $cases, callable $isOpen): array
    {
        return array_values(array_map(
            static fn (BackedEnum $case): string => (string) $case->value,
            array_filter($cases, $isOpen),
        ));
    }
}
