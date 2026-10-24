<?php

declare(strict_types=1);

namespace App\Application\Campaign\Launch;

use App\Application\Campaign\Contract\BlueprintRepository;
use App\Application\Campaign\Contract\LaunchRepository;
use App\Domain\Advertising\Blueprint\BlueprintStatus;
use App\Domain\Advertising\Launch\ActivationStatus;
use App\Domain\Advertising\Launch\ExecutionStatus;

/**
 * The plan's state after activation. Every verified account confirmed active: `active`. Some: still `activation_pending`, saying
 * how many — the plan is not called active while part of it is not. None: back to `verified`, saying why.
 */
final readonly class ActivationProgress
{
    public function __construct(
        private LaunchRepository $launches,
        private BlueprintRepository $blueprints,
    ) {}

    public function settle(string $workspaceId, string $blueprintId): void
    {
        $all = $this->launches->executionsForBlueprint($workspaceId, $blueprintId);
        $latest = $all[0]->launchId ?? null;
        $verified = array_values(array_filter($all, static fn ($e): bool => $e->launchId === $latest && $e->status === ExecutionStatus::Verified));
        $statuses = array_map(static fn ($e): ?ActivationStatus => $e->activationStatus, $verified);
        $active = count(array_filter($statuses, static fn (?ActivationStatus $s): bool => $s === ActivationStatus::Active));

        /* Still in flight: nothing is settled until the platform has answered. */
        if (in_array(ActivationStatus::Activating, $statuses, true) || in_array(ActivationStatus::Sending, $statuses, true)) {
            return;
        }

        $unknown = count(array_filter($statuses, static fn (?ActivationStatus $s): bool => $s === ActivationStatus::Unknown));

        match (true) {
            /* An account that may be switched on is never reported as "nothing was switched on". */
            $unknown > 0 => $this->blueprints->setStatus($workspaceId, $blueprintId, BlueprintStatus::ActivationPending, sprintf('%d account(s) with an unknown switch-on outcome — check the platform; active on %d of %d.', $unknown, $active, count($verified)), null),
            $verified !== [] && $active === count($verified) => $this->blueprints->setStatus($workspaceId, $blueprintId, BlueprintStatus::Active, null, null),
            $active > 0 => $this->blueprints->setStatus($workspaceId, $blueprintId, BlueprintStatus::ActivationPending, sprintf('Active on %d of %d accounts.', $active, count($verified)), null),
            default => $this->blueprints->setStatus($workspaceId, $blueprintId, BlueprintStatus::Verified, 'Nothing was switched on; see each account.', null),
        };
    }
}
