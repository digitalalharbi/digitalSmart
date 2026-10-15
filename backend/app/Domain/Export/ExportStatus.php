<?php

declare(strict_types=1);

namespace App\Domain\Export;

enum ExportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
