<?php

declare(strict_types=1);

namespace App\Infrastructure\Advertising\Sandbox;

use App\Application\Advertising\DTO\Page;
use App\Domain\Integration\Provider;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * Reads provider-shaped sandbox fixtures off disk.
 *
 * The files are deliberately raw: they hold what the provider's API returns, so
 * the mapper under test is the same mapper that will face the live endpoint.
 * This class does three things and no more — load, resolve relative dates, and
 * paginate — because every transformation here is a transformation the real
 * adapter will not perform.
 *
 * See database/fixtures/providers/README.md.
 */
final class SandboxFixtures
{
    /**
     * Rows per page for structure.
     *
     * Deliberately tiny, so every sync crosses several page boundaries and
     * cursor handling is exercised rather than assumed.
     */
    public const PAGE_SIZE = 2;

    /**
     * Rows per page for performance.
     *
     * Larger because that is what the real APIs do — Meta's default insight page
     * is 25 and Google streams whole result sets — and because paging fourteen
     * days of ad-level rows two at a time is a cost the tests pay on every run
     * for no extra coverage.
     */
    public const INSIGHT_PAGE_SIZE = 50;

    /** @var array<string, list<array<string, mixed>>> */
    private array $cache = [];

    public function __construct(
        private readonly string $basePath,
        private readonly DateTimeImmutable $today,
    ) {}

    /**
     * One page of raw rows, with `_` directives resolved and stripped.
     *
     * @param  callable(array<string, mixed>): bool|null  $filter
     * @return Page<array<string, mixed>>
     */
    public function page(Provider $provider, string $name, ?string $cursor = null, ?callable $filter = null): Page
    {
        $rows = $this->rows($provider, $name);

        if ($filter !== null) {
            $rows = array_values(array_filter($rows, $filter));
        }

        $size = $this->pageSizeFor($name);
        $offset = $this->decodeCursor($cursor);
        $slice = array_slice($rows, $offset, $size);
        $next = $offset + $size;

        return new Page(
            items: array_values($slice),
            nextCursor: $next < count($rows) ? $this->encodeCursor($next) : null,
        );
    }

    /** Insight files page like a real reporting endpoint; structure pages tightly. */
    public function pageSizeFor(string $name): int
    {
        return str_starts_with($name, 'insights_') ? self::INSIGHT_PAGE_SIZE : self::PAGE_SIZE;
    }

    /**
     * Whether this provider has fixtures at all.
     *
     * A provider with no documented contract has no directory, and that absence
     * is the honest answer rather than something to paper over.
     */
    public function has(Provider $provider, string $name): bool
    {
        return is_file($this->path($provider, $name));
    }

    /** @return list<array<string, mixed>> */
    public function rows(Provider $provider, string $name): array
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
            throw new RuntimeException("Unable to read sandbox fixture: {$path}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Malformed sandbox fixture: {$path}", previous: $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Sandbox fixture must hold a list: {$path}");
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $this->resolve($row);
            }
        }

        return $this->cache[$key] = $rows;
    }

    /**
     * Turn `_day_offset` into the date fields the provider would have sent.
     *
     * `date_start` and `date_stop` are always written at the top level, because
     * that is where the range filter looks. A row may also list `_date_paths`
     * (dotted, e.g. `segments.date`) for providers that nest the date somewhere
     * else — Google reports it under `segments`, and the mapper must find it
     * exactly where a live response would put it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function resolve(array $row): array
    {
        $offset = $row['_day_offset'] ?? null;

        if (is_int($offset) || (is_string($offset) && is_numeric($offset))) {
            $date = $this->today->modify('-'.(int) $offset.' days')->format('Y-m-d');
            $row['date_start'] = $date;
            $row['date_stop'] = $date;

            $paths = $row['_date_paths'] ?? null;

            foreach (is_array($paths) ? $paths : [] as $path) {
                if (is_string($path)) {
                    $row = $this->writePath($row, explode('.', $path), $date);
                }
            }

            /*
             * LinkedIn does not send a date string at all: its analytics rows carry
             * `dateRange: {start: {year, month, day}, end: {…}}`, three integers deep.
             * `_date_objects` writes that shape, so the fixture keeps the response
             * verbatim rather than the mapper learning a format no live row uses.
             */
            $objects = $row['_date_objects'] ?? null;
            $parts = [
                'year' => (int) $this->today->modify('-'.(int) $offset.' days')->format('Y'),
                'month' => (int) $this->today->modify('-'.(int) $offset.' days')->format('n'),
                'day' => (int) $this->today->modify('-'.(int) $offset.' days')->format('j'),
            ];

            foreach (is_array($objects) ? $objects : [] as $path) {
                if (is_string($path)) {
                    $row = $this->writePath($row, explode('.', $path), $parts);
                }
            }
        }

        foreach (array_keys($row) as $key) {
            if (str_starts_with($key, '_')) {
                unset($row[$key]);
            }
        }

        return $row;
    }

    /**
     * Set a nested value, creating intermediate arrays.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $segments
     * @param  string|array<string, int>  $value
     * @return array<string, mixed>
     */
    private function writePath(array $row, array $segments, string|array $value): array
    {
        $key = array_shift($segments);

        if ($key === null) {
            return $row;
        }

        if ($segments === []) {
            $row[$key] = $value;

            return $row;
        }

        $nested = $row[$key] ?? [];
        /** @var array<string, mixed> $nested */
        $nested = is_array($nested) ? $nested : [];
        $row[$key] = $this->writePath($nested, $segments, $value);

        return $row;
    }

    private function path(Provider $provider, string $name): string
    {
        return $this->basePath.'/'.$provider->value.'/'.$name.'.json';
    }

    private function encodeCursor(int $offset): string
    {
        // Opaque to the caller, like every real provider's cursor.
        return base64_encode('sandbox:'.$offset);
    }

    private function decodeCursor(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false || ! str_starts_with($decoded, 'sandbox:')) {
            throw new RuntimeException('Unrecognised sandbox cursor.');
        }

        return max(0, (int) substr($decoded, strlen('sandbox:')));
    }
}
