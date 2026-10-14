<?php

declare(strict_types=1);

namespace App\Domain\Integration;

use App\Domain\Client\ClientId;
use InvalidArgumentException;

/**
 * Whose credentials these are.
 *
 * An agency does not hold one set of credentials per platform. It holds its own — the MCC, the agency's
 * Business Manager — and, separately, the ones a client granted it directly, because the client would not
 * move their ad account under the agency's manager or because they will take it back when the contract ends.
 * Both are real, both are in use on the same platform at the same time, and the product has to tell them
 * apart: an operator looking at a stale token needs to know whether to re-authorise it themselves or to
 * email the client.
 *
 * It also decides what happens at offboarding. An agency-owned connection survives a client leaving; a
 * client-owned one is theirs, and a product that cannot say which is which cannot disconnect the right one.
 */
final readonly class ConnectionOwner
{
    private function __construct(
        public ConnectionOwnerType $type,
        /** Set exactly when the type is Client. */
        public ?ClientId $clientId,
    ) {}

    public static function agency(): self
    {
        return new self(ConnectionOwnerType::Agency, null);
    }

    public static function client(ClientId $clientId): self
    {
        return new self(ConnectionOwnerType::Client, $clientId);
    }

    /**
     * Rebuild from storage.
     *
     * Throws rather than coercing: a `client` row with no client id is a database that has drifted from its
     * own check constraint, and silently reading it as agency-owned would hand one client's credentials the
     * lifecycle of the agency's.
     */
    public static function reconstitute(ConnectionOwnerType $type, ?ClientId $clientId): self
    {
        if ($type === ConnectionOwnerType::Client && $clientId === null) {
            throw new InvalidArgumentException('A client-owned connection must name its client.');
        }

        if ($type === ConnectionOwnerType::Agency && $clientId !== null) {
            throw new InvalidArgumentException('An agency-owned connection cannot name a client.');
        }

        return new self($type, $clientId);
    }

    public function isAgency(): bool
    {
        return $this->type === ConnectionOwnerType::Agency;
    }

    public function belongsTo(ClientId $clientId): bool
    {
        return $this->clientId !== null && $this->clientId->equals($clientId);
    }

    public function equals(self $other): bool
    {
        if ($this->type !== $other->type) {
            return false;
        }

        return $this->clientId === null
            ? $other->clientId === null
            : $other->clientId !== null && $this->clientId->equals($other->clientId);
    }
}
