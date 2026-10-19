<?php

declare(strict_types=1);

namespace App\Application\Automation;

use App\Application\Agents\Contract\AgentReadModel;
use App\Domain\Automation\RuleAction;
use App\Domain\Integration\Provider;
use App\Domain\Integration\WriteSupport;

/**
 * Whether a rule's action can be carried out on a platform — one verdict shared by the builder, the dry run, saving
 * and the routine, so none of them promises what another would refuse.
 *
 * - `supported`: nothing to write on a platform (notify, a task), or a write read in the platform's own documentation
 *   on a platform this workspace has connected.
 * - `awaiting_credentials`: the write is documented, the platform is not connected yet.
 * - `unverified`: the platform's write has not been verified here.
 * - `unsupported`: no such write, and bid changes everywhere — no platform's bid write is wired.
 */
final readonly class RuleCapability
{
    public function __construct(private AgentReadModel $agents) {}

    /** @return 'supported'|'awaiting_credentials'|'unverified'|'unsupported' */
    public function of(RuleAction $action, string $provider, string $workspaceId): string
    {
        if ($action === RuleAction::ProposeBidChange) {
            return 'unsupported';
        }

        $operation = $action->writeOperation();

        if ($operation === null) {
            return 'supported';
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
