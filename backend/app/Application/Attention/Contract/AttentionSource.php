<?php

declare(strict_types=1);

namespace App\Application\Attention\Contract;

use App\Application\Attention\DTO\AttentionItem;
use App\Domain\Attention\AttentionStream;

/**
 * One module's contribution to the attention queue.
 *
 * Each module already knows what is wrong inside it — budget pacing, tracking issues, approval ages,
 * overdue promises, campaign health. A source is the adapter that says it in the queue's words, and it
 * exists so the queue does not grow its own second opinion about any of them. A figure computed twice is
 * a figure that can disagree with itself, and the version on the home screen is the one nobody checks.
 */
interface AttentionSource
{
    public function stream(): AttentionStream;

    /**
     * Everything this module is currently raising, worst first.
     *
     * The caller has already checked the stream's permission, so an implementation may call a query that
     * guards on it without expecting a refusal.
     *
     * @return list<AttentionItem>
     */
    public function items(): array;
}
