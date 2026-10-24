<?php

declare(strict_types=1);

namespace App\Application\Campaign\Query;

use App\Application\Campaign\Contract\LaunchRepository;
use App\Application\Campaign\DTO\ExecutionRecord;
use App\Application\Shared\Contract\ActorContext;

/** Every account's part of every launch of a plan the reader may see, newest launch first. */
final readonly class ReadLaunches
{
    public function __construct(
        private ReadBlueprints $read,
        private LaunchRepository $launches,
        private ActorContext $actor,
    ) {}

    /** @return list<ExecutionRecord> */
    public function __invoke(string $blueprintId): array
    {
        $this->read->get($blueprintId);

        return $this->launches->executionsForBlueprint($this->actor->workspaceId()->value, $blueprintId);
    }
}
