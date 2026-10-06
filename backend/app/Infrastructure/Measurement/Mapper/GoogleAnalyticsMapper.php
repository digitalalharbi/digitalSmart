<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement\Mapper;

use App\Application\Measurement\DTO\DataStreamRecord;
use App\Application\Measurement\DTO\DiscoveredProperty;
use App\Application\Measurement\DTO\KeyEventRecord;
use App\Application\Measurement\DTO\ObservedEventRecord;
use App\Domain\Advertising\ValueObject\Money;
use App\Domain\Measurement\ReportQuality;

/**
 * Google Analytics 4 responses to normalised records.
 *
 * Two APIs with different shapes, both documented, both mapped here rather than in the adapter
 * so the mapping is testable against raw fixtures without any transport:
 *
 *  - **Admin API v1beta** (`analyticsadmin.googleapis.com`): `accountSummaries.list` returns
 *    `accountSummaries[].propertySummaries[]`; `properties.dataStreams.list` returns
 *    `dataStreams[]` with one of three mutually exclusive sub-objects; `properties.keyEvents`
 *    returns `keyEvents[]`.
 *  - **Data API v1beta** (`analyticsdata.googleapis.com`): `runReport` returns
 *    `dimensionHeaders[]`, `metricHeaders[]` and `rows[]`, where each row holds
 *    `dimensionValues[]` and `metricValues[]` as **positional** arrays of `{value}`.
 *
 * That last detail governs the design of this class. A row is positional, so the only safe way
 * to read it is through the headers that describe the columns. Reading `dimensionValues[0]`
 * because that is the order we asked for would work right up until somebody adds a dimension
 * to the request, at which point every date in the database becomes an event name — silently,
 * and with the figures still looking plausible. So the mapper builds an index from the headers
 * and reads by name.
 */
final readonly class GoogleAnalyticsMapper
{
    /** The dimension holding the day, as `date` reports it: `YYYYMMDD`. */
    public const string DIMENSION_DATE = 'date';

    /** The dimension holding the raw event name. */
    public const string DIMENSION_EVENT_NAME = 'eventName';

    /**
     * The count metric.
     *
     * `eventCount` — "The count of events." The one metric this product needs from GA4 and the
     * one it can rely on existing, since it is a standard metric on every property.
     */
    public const string METRIC_EVENT_COUNT = 'eventCount';

    /**
     * Properties the connection can read, each with its streams.
     *
     * Streams are passed in by property rather than fetched here: `accountSummaries.list`
     * returns no stream data, so the adapter makes a second call per property and this method
     * joins the two. Done here so the join is covered by the mapper's own tests.
     *
     * @param  array<string, mixed>  $accountSummary  one `accountSummaries[]` entry
     * @param  array<string, list<DataStreamRecord>>  $streamsByProperty  keyed by `properties/123456`
     * @return list<DiscoveredProperty>
     */
    public function properties(array $accountSummary, array $streamsByProperty = []): array
    {
        $accountName = $this->string($accountSummary, 'account') ?? '';
        $accountLabel = $this->string($accountSummary, 'displayName') ?? $accountName;
        $summaries = $accountSummary['propertySummaries'] ?? [];
        $properties = [];

        foreach (is_array($summaries) ? $summaries : [] as $summary) {
            if (! is_array($summary)) {
                continue;
            }

            /** @var array<string, mixed> $summary */
            $property = $this->string($summary, 'property');

            if ($property === null || $property === '') {
                // A summary with no resource name cannot be read from or stored against.
                continue;
            }

            $properties[] = new DiscoveredProperty(
                propertyId: $property,
                /*
                 * Falling back to the resource name rather than inventing a label: an unnamed
                 * property is unusual but "properties/123456" is at least true, and a cheerful
                 * "Untitled property" would be this product making something up.
                 */
                displayName: $this->string($summary, 'displayName') ?? $property,
                accountName: $accountName,
                accountDisplayName: $accountLabel,
                propertyType: $this->string($summary, 'propertyType'),
                streams: $streamsByProperty[$property] ?? [],
            );
        }

        return $properties;
    }

    /**
     * One `dataStreams[]` entry.
     *
     * The three sub-objects are mutually exclusive in the API, which is why the identifying
     * fields are flattened: a caller asking "what is this stream for?" should not have to know
     * which of `webStreamData`, `androidAppStreamData` or `iosAppStreamData` to look in.
     *
     * @param  array<string, mixed>  $row
     */
    public function dataStream(array $row): DataStreamRecord
    {
        $type = $this->string($row, 'type') ?? 'DATA_STREAM_TYPE_UNSPECIFIED';
        $web = $this->nested($row, 'webStreamData');
        $android = $this->nested($row, 'androidAppStreamData');
        $ios = $this->nested($row, 'iosAppStreamData');

        return new DataStreamRecord(
            streamId: $this->string($row, 'name') ?? '',
            displayName: $this->string($row, 'displayName') ?? '',
            type: $type,
            measurementId: $this->string($web, 'measurementId'),
            defaultUri: $this->string($web, 'defaultUri'),
            // Whichever app field is present; both are the store identifier for their platform.
            appIdentifier: $this->string($android, 'packageName') ?? $this->string($ios, 'bundleId'),
        );
    }

    /**
     * One `keyEvents[]` entry.
     *
     * `defaultValue.numericValue` is a double in the API. It is carried as a decimal string
     * rather than a float because it is money, and money that has been through a float is money
     * that no longer reconciles.
     *
     * @param  array<string, mixed>  $row
     */
    public function keyEvent(array $row): KeyEventRecord
    {
        $default = $this->nested($row, 'defaultValue');
        $numeric = $default['numericValue'] ?? null;

        return new KeyEventRecord(
            eventName: $this->string($row, 'eventName') ?? '',
            countingMethod: $this->string($row, 'countingMethod'),
            isCustom: (bool) ($row['custom'] ?? false),
            defaultValue: is_int($numeric) || is_float($numeric) || (is_string($numeric) && is_numeric($numeric))
                ? $this->decimalString((float) $numeric)
                : null,
            defaultCurrency: $this->string($default, 'currencyCode'),
        );
    }

    /**
     * The rows of one `runReport` response.
     *
     * Reads through the headers, never by position. A row whose date or event name cannot be
     * read is skipped rather than guessed at: an observation with no day has nowhere to go, and
     * one with no name means nothing.
     *
     * @param  array<string, mixed>  $response
     * @return list<ObservedEventRecord>
     */
    public function observedEvents(array $response, ?string $valueMetric = null, ?string $currency = null): array
    {
        $dimensions = $this->headerIndex($response, 'dimensionHeaders');
        $metrics = $this->headerIndex($response, 'metricHeaders');

        $dateAt = $dimensions[self::DIMENSION_DATE] ?? null;
        $nameAt = $dimensions[self::DIMENSION_EVENT_NAME] ?? null;
        $countAt = $metrics[self::METRIC_EVENT_COUNT] ?? null;
        /*
         * Null unless the adapter established, from the property's own metadata, that this
         * metric exists there. Absent that, no value is read at all — which leaves the figure
         * as a count, honestly, instead of a zero that would read as "this sale was worth
         * nothing".
         */
        $valueAt = $valueMetric === null ? null : ($metrics[$valueMetric] ?? null);

        if ($dateAt === null || $nameAt === null || $countAt === null) {
            return [];
        }

        $rows = $response['rows'] ?? [];
        $records = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $date = $this->valueAt($row, 'dimensionValues', $dateAt);
            $name = $this->valueAt($row, 'dimensionValues', $nameAt);

            if ($date === null || $name === null || $name === '') {
                continue;
            }

            $day = $this->day($date);

            if ($day === null) {
                continue;
            }

            if ($name === '(other)') {
                /*
                 * The bucket GA4 rolls high-cardinality values into. Skipped deliberately: it is
                 * not an event name, and storing it would create a conversion candidate called
                 * "(other)" that somebody could map. Its presence is reported as a caveat on the
                 * page instead, where it belongs.
                 */
                continue;
            }

            $count = $this->valueAt($row, 'metricValues', $countAt);
            $value = $valueAt === null ? null : $this->valueAt($row, 'metricValues', $valueAt);
            $minor = $this->minorUnits($value, $currency);

            $records[] = new ObservedEventRecord(
                rawEventName: $name,
                date: $day,
                count: $count === null ? 0 : (int) (float) $count,
                valueMinor: $minor,
                // A currency without an amount is noise; the schema refuses the reverse.
                currency: $minor === null ? null : $currency,
            );
        }

        return $records;
    }

    /**
     * What the response said about its own completeness.
     *
     * Every field here is one the provider volunteers, and dropping any of them would turn an
     * estimate into a figure a client is shown as exact.
     *
     * @param  array<string, mixed>  $response
     */
    public function quality(array $response): ReportQuality
    {
        $metadata = $this->nested($response, 'metadata');

        if ($metadata === []) {
            // No metadata block at all: nothing was claimed, so nothing is assumed.
            return ReportQuality::complete();
        }

        $samplings = $metadata['samplingMetadatas'] ?? null;
        $fraction = null;

        foreach (is_array($samplings) ? $samplings : [] as $sampling) {
            if (! is_array($sampling)) {
                continue;
            }

            /*
             * `samplesReadCount / samplingSpaceSize`, both int64 and therefore JSON strings.
             * One block per date range; the smallest fraction wins, because a figure spanning
             * a heavily sampled range and a complete one is only as trustworthy as its worst
             * part.
             */
            $read = $this->int64($sampling['samplesReadCount'] ?? null);
            $space = $this->int64($sampling['samplingSpaceSize'] ?? null);

            if ($read === null || $space === null || $space <= 0) {
                continue;
            }

            $ratio = $read / $space;
            $fraction = $fraction === null ? $ratio : min($fraction, $ratio);
        }

        $truncations = $metadata['dataTruncationReasons'] ?? null;

        return ReportQuality::of(
            samplingFraction: $fraction,
            isThresholded: (bool) ($metadata['subjectToThresholding'] ?? false),
            isTruncated: is_array($truncations) && $truncations !== [],
            hasOtherRow: (bool) ($metadata['dataLossFromOtherRow'] ?? false),
            emptyReason: $this->string($metadata, 'emptyReason'),
        );
    }

    /**
     * The property's reporting currency, as stated on the response.
     *
     * @param  array<string, mixed>  $response
     */
    public function currencyOf(array $response): ?string
    {
        return $this->string($this->nested($response, 'metadata'), 'currencyCode');
    }

    /**
     * The property's time zone — the boundary every `date` here was cut on.
     *
     * @param  array<string, mixed>  $response
     */
    public function timeZoneOf(array $response): ?string
    {
        return $this->string($this->nested($response, 'metadata'), 'timeZone');
    }

    /**
     * The provider's name for the money metric on this property, from `getMetadata`.
     *
     * Chosen by the provider's own `type` rather than by matching a name, because `TYPE_CURRENCY`
     * is GA4 stating that a metric is an amount of money. A metric carrying a `blockedReasons`
     * entry is skipped: `NO_REVENUE_METRICS` means the property will refuse to report it, and
     * asking anyway turns every sync into a 400.
     *
     * @param  array<string, mixed>  $metadata  a `properties.getMetadata` response
     */
    public function valueMetric(array $metadata, string $preferred = 'purchaseRevenue'): ?string
    {
        $candidates = [];

        foreach ($this->listOf($metadata, 'metrics') as $metric) {
            if (($metric['type'] ?? null) !== 'TYPE_CURRENCY') {
                continue;
            }

            $blocked = $metric['blockedReasons'] ?? null;

            if (is_array($blocked) && $blocked !== []) {
                continue;
            }

            $apiName = $this->string($metric, 'apiName');

            if ($apiName !== null && $apiName !== '') {
                $candidates[$apiName] = true;
            }
        }

        if ($candidates === []) {
            return null;
        }

        /*
         * Prefer the caller's choice when the property actually has it; otherwise take the
         * first currency metric the property offers. Deterministic either way — a sync that
         * picked a different metric each run would make a revenue series that moves for no
         * reason anybody could find.
         */
        if (isset($candidates[$preferred])) {
            return $preferred;
        }

        return (string) array_key_first($candidates);
    }

    /**
     * Column name to position, from a headers array.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, int>
     */
    private function headerIndex(array $response, string $key): array
    {
        $index = [];
        $position = 0;

        foreach ($this->listOf($response, $key) as $header) {
            $name = $this->string($header, 'name');

            if ($name !== null && $name !== '' && ! isset($index[$name])) {
                $index[$name] = $position;
            }

            $position++;
        }

        return $index;
    }

    /**
     * The `value` at one position of a row's `dimensionValues` or `metricValues`.
     *
     * @param  array<string, mixed>  $row
     */
    private function valueAt(array $row, string $key, int $position): ?string
    {
        $values = $row[$key] ?? null;

        if (! is_array($values) || ! isset($values[$position]) || ! is_array($values[$position])) {
            return null;
        }

        $value = $values[$position]['value'] ?? null;

        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : null);
    }

    /** `YYYYMMDD` to `Y-m-d`, or null when it is neither. */
    private function day(string $raw): ?string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $matches) !== 1) {
            /*
             * GA4 returns `(other)` in the date dimension when days are rolled up, and
             * `NdaysAgo`-style strings never appear in responses. Either way a row we cannot
             * place on a day is dropped rather than parked on today, which would move somebody
             * else's traffic into this morning.
             */
            return null;
        }

        return sprintf('%s-%s-%s', $matches[1], $matches[2], $matches[3]);
    }

    /**
     * A currency metric's decimal string to minor units.
     *
     * GA4 sends currency as a floating-point string. `Money::fromDecimalString` rounds half-up
     * at two decimal places, which is right for SAR, AED, USD and EUR and wrong for the
     * zero-exponent currencies such as JPY and KWD — a known limitation of that value object
     * shared with every advertising mapper, not something introduced here. It is recorded in
     * docs/MEASUREMENT.md rather than worked around locally, because a second rounding rule
     * living in one mapper is how two parts of a product come to disagree about an amount.
     */
    private function minorUnits(?string $value, ?string $currency): ?int
    {
        if ($value === null || $currency === null || ! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        if ($amount <= 0.0) {
            /*
             * Zero revenue against an event is GA4 saying "no value on these", not "these were
             * worth nothing". Reported as null so the tracking health check can still say a
             * purchase arrived without a value — which is the fault somebody needs to hear
             * about, and a stored zero would hide it behind a figure that looks answered.
             */
            return null;
        }

        return Money::fromDecimalString($this->decimalString($amount), $currency)->minorUnits;
    }

    private function decimalString(float $amount): string
    {
        // Enough places for any ISO currency exponent; Money does the rounding.
        return number_format($amount, 4, '.', '');
    }

    /** @return int|null the int64 GA4 sends as a JSON string */
    private function int64(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function listOf(array $row, string $key): array
    {
        $values = $row[$key] ?? null;
        $list = [];

        foreach (is_array($values) ? $values : [] as $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $list[] = $value;
            }
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function nested(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
