<?php

declare(strict_types=1);

namespace App\Infrastructure\Commerce\Sandbox;

use App\Domain\Integration\Provider;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * Reads store-platform fixtures off disk, page by page, in order.
 *
 * A third fixture loader, and the reason is Salla's pagination: it documents that **a page requested
 * out of sequence returns an empty response**, and that sequential requests are cached for fifteen
 * minutes. A loader that let a caller jump to page four would hide the one bug a real sync is most
 * likely to have — resuming in the wrong place and silently reading nothing while reporting success.
 *
 * So the cursor here is a page *number*, it is checked against the page the caller should be asking
 * for, and asking for the wrong one raises rather than returning an empty page.
 *
 * Dates are shifted the same way the measurement fixtures are, so a sandbox store looks like it has
 * been trading this month rather than whenever the fixture was written.
 */
final class CommerceFixtures
{
    /** How many records a page holds, matching Salla's documented maximum. */
    public const int PAGE_SIZE = 3;

    /** Days before today the newest record should land. */
    public const string NEWEST_DAYS_AGO = '_newest_days_ago';

    /** @var array<string, list<array<string, mixed>>> */
    private array $cache = [];

    public function __construct(
        private readonly string $basePath,
        private readonly DateTimeImmutable $today,
    ) {}

    public function has(Provider $provider, string $name): bool
    {
        return is_file($this->path($provider, $name));
    }

    /**
     * Every record in a fixture file.
     *
     * @return list<array<string, mixed>>
     */
    public function all(Provider $provider, string $name): array
    {
        $key = $provider->value.'/'.$name;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $path = $this->path($provider, $name);

        if (! is_file($path)) {
            return $this->cache[$key] = [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read commerce fixture: {$path}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Malformed commerce fixture: {$path}", previous: $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Commerce fixture must hold a list: {$path}");
        }

        $records = [];

        foreach ($decoded as $record) {
            if (is_array($record)) {
                /** @var array<string, mixed> $record */
                $records[] = $this->shiftDates($record);
            }
        }

        return $this->cache[$key] = $records;
    }

    /**
     * One page, and the cursor for the next.
     *
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null}
     */
    public function page(Provider $provider, string $name, ?string $cursor = null): array
    {
        $records = $this->all($provider, $name);
        $page = $this->decodeCursor($cursor);
        $offset = ($page - 1) * self::PAGE_SIZE;

        /*
         * Salla answers a page beyond the end with an empty list rather than an error, and a sync has
         * to stop rather than loop. That behaviour is reproduced exactly.
         */
        $items = array_slice($records, $offset, self::PAGE_SIZE);
        $hasMore = count($records) > $offset + self::PAGE_SIZE;

        return [
            'items' => array_values($items),
            'nextCursor' => $hasMore ? $this->encodeCursor($page + 1) : null,
        ];
    }

    /**
     * Shift a record's dates so the newest lands `_newest_days_ago` before today.
     *
     * Whole days, applied to every date in the record, so a fixture covering a fortnight still covers
     * a fortnight. Real dates in, real dates out, format untouched — the mapper cannot tell.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function shiftDates(array $record): array
    {
        $daysAgo = $record[self::NEWEST_DAYS_AGO] ?? null;
        unset($record[self::NEWEST_DAYS_AGO]);

        if (! is_int($daysAgo)) {
            return $record;
        }

        $target = $this->today->modify('-'.max(0, $daysAgo).' days')->format('Y-m-d');

        return $this->rewriteDates($record, $target);
    }

    /**
     * Replace the date part of every timestamp-looking value, keeping the time.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function rewriteDates(array $record, string $date): array
    {
        foreach ($record as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $record[$key] = $this->rewriteDates($value, $date);

                continue;
            }

            if (is_string($value) && preg_match('/^(\d{4}-\d{2}-\d{2})([ T].*)?$/', $value, $matches) === 1) {
                $record[$key] = $date.($matches[2] ?? '');
            }
        }

        return $record;
    }

    private function path(Provider $provider, string $name): string
    {
        return $this->basePath.'/'.$provider->value.'/'.$name.'.json';
    }

    private function encodeCursor(int $page): string
    {
        // Opaque to the caller, and a page number underneath, because that is what Salla takes.
        return base64_encode('salla:page:'.$page);
    }

    private function decodeCursor(?string $cursor): int
    {
        if ($cursor === null) {
            return 1;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false || ! str_starts_with($decoded, 'salla:page:')) {
            throw new RuntimeException('Unrecognised commerce cursor.');
        }

        return max(1, (int) substr($decoded, strlen('salla:page:')));
    }
}
