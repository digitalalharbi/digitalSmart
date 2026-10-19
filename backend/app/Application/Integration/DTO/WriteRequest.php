<?php

declare(strict_types=1);

namespace App\Application\Integration\DTO;

use App\Domain\Integration\Credentials;
use App\Domain\Integration\Provider;
use App\Domain\Integration\WriteOperation;

/**
 * Everything a writer needs, and nothing it could widen.
 *
 * There is no "fields" bag and no passthrough. A caller can ask for one of three operations against one campaign,
 * and the only variable payload is a daily budget in minor units — which the budget operation requires and the
 * other two must not carry. An adapter that accepted arbitrary fields would be an adapter through which anything
 * could be changed, and the approval that authorised this one change would be authorising all of them.
 */
final readonly class WriteRequest
{
    public function __construct(
        public Provider $provider,
        public WriteOperation $operation,
        public Credentials $credentials,
        /** The provider's own account identifier, as discovered. */
        public string $externalAccountId,
        /** The provider's own campaign identifier. */
        public string $externalCampaignId,
        /** Minor units, in the account's currency. Required for a budget change, null otherwise. */
        public ?int $dailyBudgetMinor = null,
        /** The account's currency, which some providers require alongside the amount. */
        public ?string $currency = null,
        /** The id of what is changed — the campaign itself, or the ad group or ad the operation's level names. */
        public ?string $externalEntityId = null,
    ) {}

    public function entityId(): string
    {
        return $this->externalEntityId ?? $this->externalCampaignId;
    }
}
