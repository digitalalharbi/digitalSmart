<?php

declare(strict_types=1);

namespace App\Infrastructure\Integration\Writer;

use App\Application\Integration\Contract\ProviderWriter;
use App\Application\Integration\DTO\WriteRequest;
use App\Domain\Integration\WriteFailure;
use App\Domain\Integration\WriteOutcome;

/**
 * The writer for a provider this product will not write to.
 *
 * Reached only if a refusal higher up were ever removed — `CarryOutApproval` checks `WriteSupport` before a
 * request is built, and the registry resolves this writer for anything that is not documented. It exists so that
 * the unsafe path has a floor: a registry that threw, or returned null, would eventually be a registry somebody
 * made lenient to fix an unrelated bug.
 *
 * It answers a normalised failure rather than throwing, like every other writer, so the caller's single code path
 * records it in the audit trail.
 */
final readonly class RefusingCampaignWriter implements ProviderWriter
{
    public function handles(WriteRequest $request): bool
    {
        return true;
    }

    public function execute(WriteRequest $request): WriteOutcome
    {
        $support = $request->provider->writeSupport($request->operation);

        return WriteOutcome::failed(
            $request->operation,
            $request->provider,
            WriteFailure::NotAuthorised,
            $support->because() ?? 'This product does not write to this provider.',
        );
    }
}
