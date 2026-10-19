<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Writer;

use App\Application\Integration\Contract\ProviderWriter;
use App\Application\Integration\DTO\WriteRequest;
use App\Domain\Advertising\MetricSource;
use App\Domain\Integration\WriteFailure;
use App\Domain\Integration\WriteOutcome;

/**
 * The write path, against sandbox credentials.
 *
 * **BLOCKED_EXTERNAL.** No advertising platform has issued this repository credentials, so no live write has ever
 * been made, and a writer that pretended otherwise would be the most dangerous class in the product. What exists
 * is the whole contract — the gates, the audit trail, the read-back, the normalised failures — exercised against
 * a connection configured with the sandbox token.
 *
 * Three rules make a simulated write safe to have in the codebase:
 *
 *  - **It refuses anything that is not the sandbox token.** A real credential reaching this class would mean a
 *    missing live adapter was silently substituted, so it answers `NotAuthorised` and names the gap rather than
 *    simulating success against a client's account.
 *  - **Every outcome is `SANDBOX`.** It travels into the audit entry, so a simulated pause is a simulated pause
 *    in the record for ever. An audit trail recording one as real is worse than no audit trail.
 *  - **It confirms by reporting the state it was asked for**, which is exactly what a real writer must *not* do:
 *    a real one reads the entity back. The distinction is stated here so that the live adapter cannot be written
 *    by copying this one.
 *
 * The documented contract for each provider is on `Provider::writeReference()`, read from each provider's own
 * current documentation. The live adapters are the remaining work, and the missing piece is a credential rather
 * than a design.
 */
final readonly class SandboxCampaignWriter implements ProviderWriter
{
    public function handles(WriteRequest $request): bool
    {
        /* Every provider whose write this product will attempt at all. The registry has already filtered. */
        return $request->provider->writeSupport($request->operation)->allowsAttempt();
    }

    public function execute(WriteRequest $request): WriteOutcome
    {
        if (! $request->credentials->isSandbox($request->provider->requiredCredentialKeys())) {
            /*
             * A real credential here means a live adapter is missing and this one was reached instead. Answering
             * `NotAuthorised` with that sentence is the only safe behaviour: the alternative is a simulated
             * success recorded against a client's live campaign.
             */
            return WriteOutcome::failed(
                $request->operation,
                $request->provider,
                WriteFailure::NotAuthorised,
                sprintf(
                    'No live write adapter exists for %s yet. This connection holds real credentials, and this '
                    .'product will not simulate a change against them. Documented contract: %s',
                    $request->provider->label(),
                    $request->provider->writeReference($request->operation) ?? 'none recorded',
                ),
            );
        }

        if ($request->operation->isBudget()) {
            $amount = $request->dailyBudgetMinor;

            if ($amount === null || $amount <= 0) {
                /* The same refusal a provider would give, so the failure path is exercised rather than theorised. */
                return WriteOutcome::failed(
                    $request->operation,
                    $request->provider,
                    WriteFailure::Rejected,
                    'A daily budget must be a positive amount in minor units.',
                    source: MetricSource::Sandbox,
                );
            }

            return WriteOutcome::confirmed(
                $request->operation,
                $request->provider,
                sprintf('daily_budget=%d', $amount),
                MetricSource::Sandbox,
                providerRequestId: 'sandbox-'.substr(sha1($request->entityId().$amount), 0, 12),
            );
        }

        return WriteOutcome::confirmed(
            $request->operation,
            $request->provider,
            $request->operation->isPause() ? 'PAUSED' : 'ACTIVE',
            MetricSource::Sandbox,
            providerRequestId: 'sandbox-'.substr(sha1($request->entityId()), 0, 12),
        );
    }
}
