<?php

declare(strict_types=1);

namespace App\Application\Platform\Command;

use App\Application\Platform\Contract\PlatformOperators;
use App\Application\Platform\Exception\PlatformRefused;
use App\Application\Shared\Contract\AuditRecorder;
use App\Application\Shared\Exception\ResourceNotFound;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditEntry;
use App\Domain\Identity\Email;

/** Make somebody a platform operator, or stop. Only from the console, by whoever holds the server. */
final readonly class SetPlatformOperator
{
    public function __construct(
        private PlatformOperators $operators,
        private AuditRecorder $audit,
    ) {}

    public function __invoke(Email $email, bool $operator, string $by, string $reason): void
    {
        if (trim($reason) === '' || trim($by) === '') {
            throw PlatformRefused::reasonRequired();
        }

        $userId = $this->operators->set($email, $operator) ?? throw ResourceNotFound::of('User');

        $this->audit->record(new AuditEntry(
            workspaceId: null,
            actorId: null,
            action: AuditAction::PlatformOperatorChanged,
            auditableType: 'user',
            auditableId: $userId,
            summary: sprintf('%s %s platform operator access.', $by, $operator ? 'granted' : 'withdrew'),
            changes: ['operator' => $operator, 'by' => $by, 'reason' => $reason],
        ));
    }
}
