<?php

declare(strict_types=1);

namespace App\Application\Tracking\Service;

use App\Application\Tracking\Contract\ConversionSender;
use App\Application\Tracking\DTO\DispatchOutcome;
use App\Application\Tracking\DTO\DispatchRequest;

/**
 * Simulates a delivery for a connection that holds sandbox credentials.
 *
 * It claims nothing a provider did not say: `received` and `deduplicated` stay null, because no provider answered. It refuses
 * a request that is not marked sandbox, so a live account can never be "delivered to" by a simulation.
 */
final readonly class SandboxConversionSender implements ConversionSender
{
    public function send(DispatchRequest $request): DispatchOutcome
    {
        if (! $request->sandbox) {
            return DispatchOutcome::rejected('live_transport_blocked');
        }

        return DispatchOutcome::sent('sandbox-'.substr(hash('sha256', $request->provider.'|'.$request->eventId), 0, 16));
    }
}
