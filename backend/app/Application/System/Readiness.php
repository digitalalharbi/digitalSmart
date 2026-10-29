<?php

declare(strict_types=1);

namespace App\Application\System;

/**
 * The words a readiness check speaks in, and how many of them make a verdict.
 *
 * `fail`: real users would be exposed or the application cannot work. `warn`: something honest but incomplete — email that is only
 * logged, realtime switched off — that the owner should know and that is not a reason to refuse to serve.
 */
final class Readiness
{
    public const string PASS = 'pass';

    public const string WARN = 'warn';

    public const string FAIL = 'fail';

    /** @param  list<array{key: string, status: string, detail: string}>  $checks */
    public static function overall(array $checks, bool $strict = false): string
    {
        $statuses = array_column($checks, 'status');

        return match (true) {
            in_array(self::FAIL, $statuses, true) => self::FAIL,
            in_array(self::WARN, $statuses, true) => $strict ? self::FAIL : self::WARN,
            default => self::PASS,
        };
    }
}
