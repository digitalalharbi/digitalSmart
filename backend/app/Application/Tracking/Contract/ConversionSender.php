<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

use App\Application\Tracking\DTO\DispatchOutcome;
use App\Application\Tracking\DTO\DispatchRequest;

/**
 * Delivers one conversion to one provider.
 *
 * The only implementation here simulates, for sandbox credentials, and says so in everything it returns. A live
 * implementation per provider is `BLOCKED_EXTERNAL`: it needs that provider's approved app, the account owner's
 * authorisation and a real account to verify against. This port is where it plugs in, with the same outcome vocabulary.
 */
interface ConversionSender
{
    public function send(DispatchRequest $request): DispatchOutcome;
}
