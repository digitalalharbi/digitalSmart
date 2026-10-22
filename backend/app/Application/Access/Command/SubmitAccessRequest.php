<?php

declare(strict_types=1);

namespace App\Application\Access\Command;

use App\Application\Access\Contract\AccessRequestRepository;
use App\Application\Shared\Contract\Clock;
use App\Application\Shared\Contract\IdentifierGenerator;
use App\Domain\Access\AccessRequestNeed;

/**
 * Somebody with no account asks for one.
 *
 * Public, so it is deliberately incapable of telling the caller anything it would not tell a stranger: it answers the
 * same whether the request was stored, was a repeat of one already waiting, or was dropped because the installation
 * takes none — a form that said "that address is already waiting" would be a way to ask who has applied.
 *
 * It creates no account and sends nothing. There is no sender identity to send with, and an account made on an unproven
 * address would belong to whoever typed it.
 */
final readonly class SubmitAccessRequest
{
    /** A second request from the same address inside this window is the same request pressed twice. */
    private const string REPEAT_WINDOW = '-24 hours';

    public function __construct(
        private AccessRequestRepository $requests,
        private IdentifierGenerator $ids,
        private Clock $clock,
    ) {}

    public function __invoke(
        string $name,
        string $email,
        ?string $company,
        ?string $phone,
        AccessRequestNeed $need,
        ?string $message,
        string $locale,
    ): void {
        $workspaceId = $this->requests->intakeWorkspaceId();

        if ($workspaceId === null) {
            return;
        }

        $now = $this->clock->now();
        $address = mb_strtolower(trim($email));

        if ($this->requests->hasPending($workspaceId, $address, $now->modify(self::REPEAT_WINDOW))) {
            return;
        }

        $this->requests->add(
            $this->ids->generate(),
            $workspaceId,
            trim($name),
            $address,
            $company === null || trim($company) === '' ? null : trim($company),
            $phone === null || trim($phone) === '' ? null : trim($phone),
            $need,
            $message === null || trim($message) === '' ? null : trim($message),
            $locale,
            $now,
        );
    }
}
