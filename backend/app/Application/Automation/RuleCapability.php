<?php

declare(strict_types=1);

namespace App\Application\Automation;

use App\Application\Agents\Contract\AgentReadModel;
use App\Domain\Advertising\AdEntityType;
use App\Domain\Automation\RuleAction;
use App\Domain\Integration\Provider;
use App\Domain\Integration\WriteSupport;

/**
 * Whether a rule's action can be carried out on a platform **at a level** — one verdict shared by the builder, the
 * dry run, saving and the routine, so none of them promises what another would refuse. Provider × level × action:
 * a platform that can pause a campaign is not thereby assumed able to pause an ad.
 *
 * - `supported`: nothing to write on a platform (notify, a task), or a write read in the platform's own documentation
 *   on a platform this workspace has connected.
 * - `awaiting_credentials`: the write is documented, the platform is not connected yet.
 * - `unverified`: the platform's write has not been verified here.
 * - `unsupported`: no such write at this level (an ad has no budget), and bid changes everywhere — no platform's bid
 *   write is wired.
 */
final readonly class RuleCapability
{
    public function __construct(private AgentReadModel $agents) {}

    /** @return 'supported'|'awaiting_credentials'|'unverified'|'unsupported' */
    public function of(RuleAction $action, string $provider, string $workspaceId, AdEntityType $level = AdEntityType::Campaign): string
    {
        if ($action === RuleAction::ProposeBidChange || ! $action->availableAt($level)) {
            return 'unsupported';
        }

        $operation = $action->writeOperation($level);

        if ($operation === null) {
            /* Notify and a task write nothing to a platform; a platform change with no operation here is refused. */
            return in_array($action, [RuleAction::Alert, RuleAction::Task], true) ? 'supported' : 'unsupported';
        }

        $platform = Provider::tryFrom($provider);

        if ($platform === null) {
            return 'unsupported';
        }

        return match ($platform->writeSupport($operation)) {
            WriteSupport::Documented => in_array($provider, $this->agents->connectedProviders($workspaceId), true) ? 'supported' : 'awaiting_credentials',
            WriteSupport::Unverified => 'unverified',
            default => 'unsupported',
        };
    }
}
