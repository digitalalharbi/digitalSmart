<?php

declare(strict_types=1);

namespace App\Application\Reporting\Contract;

use App\Application\Reporting\DTO\RenderedReport;

/**
 * Turning a rendered report into the shape a snapshot stores and a reader receives.
 *
 * A port because the application layer must not know about HTTP, and the shape a snapshot stores has to be
 * **exactly** the shape a live render returns — otherwise a frozen report and a live one would render differently
 * in the same interface, which is the one difference this module cannot afford to have.
 *
 * So there is one implementation, it lives in the interface layer beside the resource that formats a live render,
 * and both go through it.
 */
interface SnapshotSerialiser
{
    /** @return array<string, mixed> */
    public function toArray(RenderedReport $report): array;
}
