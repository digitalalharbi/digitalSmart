<?php

declare(strict_types=1);

namespace App\Application\Commerce\Contract;

use App\Application\Commerce\DTO\StoreIdentity;
use App\Domain\Integration\Provider;

/**
 * Finds which workspace a store belongs to.
 *
 * Needed by the webhook path, which arrives with no session and no workspace: the only thing it has is
 * the merchant id the provider put in the payload, and that has to resolve to a workspace before
 * anything else can happen — including deciding whose secret to verify the signature against.
 *
 * **It returns every candidate, not the first.** Stores are unique per
 * `(workspace, provider, external_id)`, so two workspaces can legitimately register the same merchant:
 * two agencies may both manage one shop, and each will have installed its own app with its own secret.
 * Taking the first match would route one agency's client's orders into the other's workspace — a
 * cross-tenant leak that nothing downstream could detect. The caller resolves the ambiguity by
 * verifying the signature against each candidate's own secret, and exactly one will match.
 *
 * Deliberately narrow all the same: a webhook handler has no business being able to read stores in
 * general.
 */
interface StoreLocator
{
    /**
     * Every store registered against this merchant id, across workspaces.
     *
     * @return list<StoreIdentity>
     */
    public function findAllByExternalId(Provider $provider, string $externalId): array;
}
