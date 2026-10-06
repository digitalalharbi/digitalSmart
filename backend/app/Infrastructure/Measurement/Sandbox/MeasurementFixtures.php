<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement\Sandbox;

use App\Domain\Integration\Provider;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * Reads measurement fixtures off disk, keeping them response-shaped.
 *
 * A separate loader from the advertising one, and the difference is the point. An advertising
 * fixture is a list of rows; a `runReport` fixture has to be a **whole response**, because the
 * thing most worth testing about GA4 is that the mapper reads its rows through
 * `dimensionHeaders` rather than by position, and headers only exist on the envelope. Flattening
 * them to rows would delete the bug this design prevents.
 *
 * So a report fixture is a JSON list of complete responses, one per page — exactly what a real
 * paged `runReport` returns, one response per request.
 *
 * Two transformations happen here and nowhere else:
 *
 *  - **Paging**, by index into that list, with an opaque cursor.
 *  - **Date shifting**, so a fixture written months ago still looks like last week. Real dates
 *    in, real dates out, relative distances preserved, format untouched — the mapper sees a
 *    `YYYYMMDD` string either way and cannot tell the difference. Without this, every freshness
 *    state in a sandbox workspace would read `stale`, and the tracking page would be a wall of
 *    warnings about fixtures.
 */
final class MeasurementFixtures
{
    /** The directive on a report fixture: how many days before today its newest row should land. */
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
     * Every element of a fixture file.
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
            throw new RuntimeException("Unable to read measurement fixture: {$path}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Malformed measurement fixture: {$path}", previous: $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Measurement fixture must hold a list: {$path}");
        }

        $elements = [];

        foreach ($decoded as $element) {
            if (is_array($element)) {
                /** @var array<string, mixed> $element */
                $elements[] = $this->shiftDates($element);
            }
        }

        return $this->cache[$key] = $elements;
    }

    /**
     * The first element, for a fixture that stands for a single response.
     *
     * `getMetadata` answers with one object rather than a collection. The file still holds a
     * list so every fixture reads the same way, and the object inside it stays verbatim.
     *
     * @return array<string, mixed>
     */
    public function first(Provider $provider, string $name): array
    {
        return $this->all($provider, $name)[0] ?? [];
    }

    /**
     * One page: the response at this cursor's index, and where to resume.
     *
     * @return array{response: array<string, mixed>, nextCursor: string|null}
     */
    public function page(Provider $provider, string $name, ?string $cursor = null): array
    {
        $responses = $this->all($provider, $name);
        $index = $this->decodeCursor($cursor);

        return [
            'response' => $responses[$index] ?? [],
            // Null on the last page, which is how the caller's loop terminates.
            'nextCursor' => isset($responses[$index + 1]) ? $this->encodeCursor($index + 1) : null,
        ];
    }

    /**
     * Move a response's dates forward so its newest row lands `_newest_days_ago` before today.
     *
     * The shift is a whole number of days applied to every date in the response, so a fixture
     * covering four consecutive days still covers four consecutive days. Fixtures without the
     * directive are returned untouched — an Admin API resource has no dates worth moving.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function shiftDates(array $response): array
    {
        $daysAgo = $response[self::NEWEST_DAYS_AGO] ?? null;
        unset($response[self::NEWEST_DAYS_AGO]);

        if (! is_int($daysAgo)) {
            return $response;
        }

        $position = $this->datePosition($response);

        if ($position === null) {
            return $response;
        }

        $newest = $this->newestDate($response, $position);

        if ($newest === null) {
            return $response;
        }

        $target = $this->today->modify('-'.max(0, $daysAgo).' days');
        $shift = (int) $newest->diff($target)->format('%r%a');

        if ($shift === 0) {
            return $response;
        }

        $rows = $response['rows'] ?? [];
        $shifted = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $shifted[] = is_array($row) ? $this->shiftRow($row, $position, $shift) : $row;
        }

        $response['rows'] = $shifted;

        return $response;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shiftRow(array $row, int $position, int $shift): array
    {
        $values = $row['dimensionValues'] ?? null;

        if (! is_array($values) || ! isset($values[$position]) || ! is_array($values[$position])) {
            return $row;
        }

        $raw = $values[$position]['value'] ?? null;
        $date = is_string($raw) ? $this->parse($raw) : null;

        if ($date === null) {
            // `(other)` lands in the date column when GA4 rolls days up. Left exactly as it is.
            return $row;
        }

        $values[$position]['value'] = $date
            ->modify(($shift >= 0 ? '+' : '-').abs($shift).' days')
            ->format('Ymd');
        $row['dimensionValues'] = $values;

        return $row;
    }

    /**
     * Which dimension column holds the date, from the headers.
     *
     * By name, like the mapper, so a fixture that lists its dimensions in a different order
     * still shifts the right column rather than mangling the event names.
     *
     * @param  array<string, mixed>  $response
     */
    private function datePosition(array $response): ?int
    {
        $headers = $response['dimensionHeaders'] ?? null;
        $position = 0;

        foreach (is_array($headers) ? $headers : [] as $header) {
            if (is_array($header) && ($header['name'] ?? null) === 'date') {
                return $position;
            }

            $position++;
        }

        return null;
    }

    /** @param array<string, mixed> $response */
    private function newestDate(array $response, int $position): ?DateTimeImmutable
    {
        $rows = $response['rows'] ?? [];
        $newest = null;

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $values = $row['dimensionValues'] ?? null;

            if (! is_array($values) || ! isset($values[$position]) || ! is_array($values[$position])) {
                continue;
            }

            $raw = $values[$position]['value'] ?? null;
            $date = is_string($raw) ? $this->parse($raw) : null;

            if ($date !== null && ($newest === null || $date > $newest)) {
                $newest = $date;
            }
        }

        return $newest;
    }

    private function parse(string $raw): ?DateTimeImmutable
    {
        if (preg_match('/^\d{8}$/', $raw) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Ymd', $raw);

        return $date === false ? null : $date->setTime(0, 0);
    }

    private function path(Provider $provider, string $name): string
    {
        return $this->basePath.'/'.$provider->value.'/'.$name.'.json';
    }

    private function encodeCursor(int $index): string
    {
        // Opaque to the caller, as every real provider's cursor is.
        return base64_encode('measurement:'.$index);
    }

    private function decodeCursor(?string $cursor): int
    {
        if ($cursor === null) {
            return 0;
        }

        $decoded = base64_decode($cursor, true);

        if ($decoded === false || ! str_starts_with($decoded, 'measurement:')) {
            throw new RuntimeException('Unrecognised measurement cursor.');
        }

        return max(0, (int) substr($decoded, strlen('measurement:')));
    }
}
