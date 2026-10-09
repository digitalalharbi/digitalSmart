<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Exception;

use App\Domain\Shared\Exception\DomainException;

/**
 * Why a store could not be connected, in words the person reading can act on.
 *
 * Each message names the thing to do next, because none of these are fixable from the panel that raised them:
 * the connection is on the integrations page, and the duplicate is somebody else's registration.
 */
final class StoreNotConnected extends DomainException
{
    public function errorCode(): string
    {
        return 'store_not_connected';
    }

    public static function providerCannotBeRead(string $provider): self
    {
        return new self(sprintf(
            '%s is not a store this product can read. Connect a commerce platform instead.',
            $provider,
        ));
    }

    public static function providerIsNotConnected(string $provider): self
    {
        return new self(sprintf(
            '%s is not connected to this workspace yet. Connect it on the integrations page first.',
            $provider,
        ));
    }

    public static function alreadyRegisteredTo(string $store, string $clientName): self
    {
        return new self(sprintf(
            '“%s” is already read for %s. One store belongs to one client, or every order would count twice.',
            $store,
            $clientName,
        ));
    }

    public static function notVisibleFromTheProvider(string $externalId): self
    {
        return new self(sprintf(
            'The connection can no longer see the store “%s”, so there is nothing to register.',
            $externalId,
        ));
    }
}
