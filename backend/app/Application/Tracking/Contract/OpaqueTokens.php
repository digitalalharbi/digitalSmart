<?php

declare(strict_types=1);

namespace App\Application\Tracking\Contract;

/** Random, meaningless identifiers that are safe to put in a URL, a cookie or a page. */
interface OpaqueTokens
{
    /** @param  int  $length characters, base 62 */
    public function make(int $length): string;
}
