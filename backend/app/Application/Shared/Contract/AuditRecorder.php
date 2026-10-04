<?php

declare(strict_types=1);

namespace App\Application\Shared\Contract;

use App\Domain\Audit\AuditEntry;

/**
 * Outbound port for the audit trail.
 *
 * Recording must never fail the action it describes: implementations log and
 * continue rather than throwing.
 */
interface AuditRecorder
{
    public function record(AuditEntry $entry): void;
}
