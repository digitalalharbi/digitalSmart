<?php

declare(strict_types=1);

namespace App\Interface\Console;

use App\Application\Integration\DTO\CommissioningReadout;
use App\Application\Integration\Query\ReadCommissioningState;
use App\Domain\Integration\Provider;
use App\Domain\Integration\Repository\ProviderConnectionRepository;
use App\Domain\Workspace\WorkspaceId;
use Illuminate\Console\Command;

/**
 * Ask a provider what it can be made to do, and print only what came back.
 *
 * This is the instrument live commissioning is done with. Everything it reports is read from the provider in
 * the moment: which credentials are configured, whether they are this repository's own fixtures or a real
 * account's, what discovery returned, which metrics an account actually exposes, what currency and timezone
 * it reports, and what the provider said when it refused.
 *
 * It writes nothing. A readout run against a client's live account cannot leave a figure in this product's
 * tables for somebody to mistake later for a sync.
 *
 * Credentials are never printed — only the **names** of the keys a provider requires.
 */
final class CommissionProvider extends Command
{
    protected $signature = 'commission:readout
        {provider? : A provider id, e.g. google_ads. Omit for every provider}
        {--workspace= : The workspace whose connection to read. Defaults to the only one that has it}';

    protected $description = 'Read one provider\'s live state and report the evidence level it has earned';

    public function handle(
        ReadCommissioningState $read,
        ProviderConnectionRepository $connections,
    ): int {
        $requested = $this->argument('provider');

        if (is_string($requested) && $requested !== '') {
            $provider = Provider::tryFrom($requested);

            if ($provider === null) {
                $this->components->error(sprintf(
                    'Unknown provider "%s". One of: %s.',
                    $requested,
                    implode(', ', Provider::values()),
                ));

                return self::FAILURE;
            }

            $providers = [$provider];
        } else {
            $providers = Provider::cases();
        }

        foreach ($providers as $provider) {
            $workspaceId = $this->workspaceFor($provider, $connections);

            if ($workspaceId === null) {
                $this->line('');
                $this->components->twoColumnDetail(
                    sprintf('<options=bold>%s</>', $provider->label()),
                    '<fg=gray>NOT_CONFIGURED — no workspace has this provider</>',
                );
                $this->components->bulletList([sprintf(
                    'Needs: %s. See docs/INTEGRATIONS.md for who issues them.',
                    implode(', ', $provider->requiredCredentialKeys()),
                )]);

                continue;
            }

            $this->report($read($provider, $workspaceId));
        }

        return self::SUCCESS;
    }

    private function report(CommissioningReadout $readout): void
    {
        $level = $readout->level();
        $colour = match ($level) {
            'LIVE_READ_VERIFIED' => 'green',
            'SANDBOX_VERIFIED' => 'yellow',
            'FAILED_EXTERNAL' => 'red',
            default => 'gray',
        };

        $this->line('');
        $this->components->twoColumnDetail(
            sprintf('<options=bold>%s</>', $readout->provider->label()),
            sprintf('<fg=%s>%s · %s</>', $colour, $readout->status(), $level),
        );

        $this->components->bulletList([sprintf(
            'Credentials: %s (%s).',
            $readout->isConfigured ? 'configured' : 'not configured',
            $readout->isSandbox ? 'this repository\'s fixtures' : implode(', ', $readout->requiredKeys),
        )]);

        if ($readout->health !== null) {
            $this->components->bulletList([sprintf(
                'Health: %s%s',
                $readout->health->healthy ? 'reachable' : 'not reachable',
                $readout->health->message === null ? '.' : ' — '.$readout->health->message,
            )]);
        }

        if ($readout->findings !== []) {
            $this->components->bulletList($readout->findings);
        }

        if ($readout->failure !== null) {
            /* The provider's own words, which is the sentence the ledger needs. */
            $this->components->error($readout->failure);
        }

        foreach ($readout->limitations as $limitation) {
            $this->components->warn($limitation);
        }

        if ($readout->isSandbox) {
            $this->components->warn(
                'Sandbox credentials: this proves the mapping, and nothing about any real account.',
            );
        }
    }

    private function workspaceFor(Provider $provider, ProviderConnectionRepository $connections): ?WorkspaceId
    {
        $chosen = $this->option('workspace');

        if (is_string($chosen) && $chosen !== '') {
            return new WorkspaceId($chosen);
        }

        $workspaces = $connections->workspacesWith($provider);

        if (count($workspaces) > 1) {
            /*
             * Refused rather than guessed. Commissioning is done against one named account, and picking a
             * workspace for somebody would be picking whose credentials get spent.
             */
            $this->components->warn(sprintf(
                '%s is configured in %d workspaces. Name one with --workspace.',
                $provider->label(),
                count($workspaces),
            ));

            return null;
        }

        return $workspaces[0] ?? null;
    }
}
