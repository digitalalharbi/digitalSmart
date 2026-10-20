<?php

declare(strict_types=1);

namespace App\Application\Reporting\Service;

/**
 * Takes the internal identifiers out of a report before an unauthenticated reader receives it.
 *
 * A rendered report is built for the agency, whose screens have a use for a row's grouping key (a campaign's id, so a
 * drill-down can ask about it) and for the report's own id. A visitor with a link has no use for either, and an id of
 * the database is a handle the visitor was never meant to hold. So the shared read does not send them: it sends a key
 * that identifies a row **on this link only** — `sha256(shareId|value)`, cut short, so it neither reverses to the id nor
 * means anything on another link.
 *
 * Two passes, deliberately. The first names what is known to carry an identifier. The second is a net for what is not
 * known: any string that is, in its entirety, a UUID is replaced the same way — so a field added to a section next year
 * cannot start leaking an id before somebody remembers to list it here. It runs over a stored snapshot as well, because
 * a snapshot taken before this existed holds the ids it was rendered with, and the stored payload is not rewritten.
 *
 * Filter options are not touched: they are already opaque per-link keys of their own (`NarrowSharedReport`).
 */
final readonly class ClientSafePayload
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param  array<string, mixed>  $payload  a serialised rendered report
     * @return array<string, mixed>
     */
    public function __invoke(array $payload, string $shareId): array
    {
        if (isset($payload['id']) && is_string($payload['id'])) {
            $payload['id'] = $this->opaque($shareId, $payload['id']);
        }

        if (isset($payload['sections']) && is_array($payload['sections'])) {
            foreach ($payload['sections'] as $index => $section) {
                if (! is_array($section) || ! isset($section['rows']) || ! is_array($section['rows'])) {
                    continue;
                }

                foreach ($section['rows'] as $position => $row) {
                    if (is_array($row) && isset($row['key']) && is_string($row['key'])) {
                        $section['rows'][$position]['key'] = $this->opaque($shareId, $row['key']);
                    }
                }

                $payload['sections'][$index] = $section;
            }
        }

        /** @var array<string, mixed> $net */
        $net = $this->net($payload, $shareId);

        return $net;
    }

    private function net(mixed $value, string $shareId): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $entry): mixed => $this->net($entry, $shareId), $value);
        }

        return is_string($value) && preg_match(self::UUID, $value) === 1
            ? $this->opaque($shareId, $value)
            : $value;
    }

    private function opaque(string $shareId, string $value): string
    {
        return substr(hash('sha256', $shareId.'|'.$value), 0, 12);
    }
}
