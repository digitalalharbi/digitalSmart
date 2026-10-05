<?php

declare(strict_types=1);

namespace App\Application\Advertising\Contract;

use App\Application\Advertising\DTO\SyncContext;
use App\Application\Advertising\DTO\SyncOutcome;
use App\Domain\Advertising\ProviderErrorCategory;
use App\Domain\Advertising\SyncKind;
use DateTimeImmutable;

/**
 * The record of what was synced, when, and what went wrong.
 *
 * Exists so "why is yesterday missing?" is answerable from the product rather
 * than from a log file an operator cannot read. Also holds the cursors, because
 * where a sync got to and whether it succeeded are the same question.
 */
interface SyncJournal
{
    /** @return string the sync run's id */
    public function begin(
        SyncContext $context,
        SyncKind $kind,
        ?DateTimeImmutable $rangeStart = null,
        ?DateTimeImmutable $rangeEnd = null,
    ): string;

    public function complete(string $runId, SyncOutcome $outcome): void;

    /**
     * Record a normalised provider failure against a run.
     *
     * Separate from `complete` because one run can hit several failures — a
     * rate limit on page four, an auth expiry on a later level — and losing all
     * but the last would hide the pattern.
     */
    public function recordError(
        string $runId,
        SyncContext $context,
        ProviderErrorCategory $category,
        string $message,
        ?int $statusCode = null,
        bool $retryable = false,
        ?int $retryAfterSeconds = null,
    ): void;

    /** The stored pagination cursor for a structure kind, if any. */
    public function cursorFor(SyncContext $context, SyncKind $kind): ?string;

    public function rememberCursor(SyncContext $context, SyncKind $kind, ?string $cursor): void;

    /**
     * How far performance data has been read, and how far back to re-read.
     *
     * @return array{syncedThrough: ?DateTimeImmutable, resyncFrom: ?DateTimeImmutable}
     */
    public function watermarkFor(SyncContext $context, SyncKind $kind): array;

    public function rememberWatermark(
        SyncContext $context,
        SyncKind $kind,
        DateTimeImmutable $syncedThrough,
        DateTimeImmutable $resyncFrom,
    ): void;

    public function recordFailure(SyncContext $context, SyncKind $kind): void;
}
