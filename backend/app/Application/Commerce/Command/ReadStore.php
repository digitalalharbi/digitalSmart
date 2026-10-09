<?php

declare(strict_types=1);

namespace App\Application\Commerce\Command;

use App\Application\Commerce\Contract\StoreDirectory;
use App\Application\Commerce\DTO\StoreSyncResult;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\UserId;
use App\Domain\Workspace\WorkspaceId;
use DateTimeImmutable;

/**
 * Read one store and record that it was read.
 *
 * No permission check and no actor context, deliberately: this is the inner use case, shared by the request
 * path (`ReadStoreNow`, which checks both) and by the queue and the console, where there is no actor to check.
 * A scheduled read is recorded with no actor, which the audit trail shows as the system — and that is the
 * honest entry, rather than attributing a nightly job to whoever last signed in.
 *
 * **The result is returned whole.** Succeeded, partial, failed and skipped are four different facts: a partial
 * read wrote real orders and keeps them, and a skipped one means the provider has no adapter to read with.
 */
final readonly class ReadStore
{
    public function __construct(
        private StoreDirectory $stores,
        private SyncStore $sync,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(
        WorkspaceId $workspaceId,
        string $storeId,
        ?UserId $actorId = null,
        ?DateTimeImmutable $since = null,
    ): StoreSyncResult {
        /* Not narrowed by project visibility: the caller decides who may ask. */
        $store = $this->stores->find($workspaceId->value, null, $storeId)
            ?? throw ResourceNotFound::of('Store');

        $result = ($this->sync)(
            $workspaceId->value,
            $store->id,
            $store->provider,
            $store->externalId,
            $since,
        );

        $this->audit->record(new AuditEntry(
            workspaceId: $workspaceId,
            actorId: $actorId,
            action: AuditAction::StoreRead,
            auditableType: 'store',
            auditableId: $store->id,
            summary: sprintf(
                'Read the store "%s": %s.',
                $store->name,
                $result->failureMessage ?? sprintf(
                    '%d order(s), %d product(s), %d customer(s)',
                    $result->ordersWritten,
                    $result->productsWritten,
                    $result->customersWritten,
                ),
            ),
            changes: [
                'status' => $result->status->value,
                'orders' => $result->ordersWritten,
                'products' => $result->productsWritten,
                'customers' => $result->customersWritten,
                'api_calls' => $result->apiCalls,
                /* The honesty label, recorded: a sandbox read is not evidence of a live one. */
                'source' => $result->source->value,
            ],
        ));

        return $result;
    }
}
