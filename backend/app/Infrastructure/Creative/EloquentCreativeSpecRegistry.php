<?php

declare(strict_types=1);

namespace App\Infrastructure\Creative;

use App\Application\Creative\Contract\CreativeSpecRegistry;
use App\Domain\Creative\AssetKind;
use App\Domain\Creative\CreativeSpec;
use App\Domain\Creative\SpecBound;
use App\Domain\Creative\SpecProvenance;
use App\Domain\Creative\SpecRequirement;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\DB;

/**
 * Recorded platform specifications, read from the database.
 *
 * Not workspace-scoped, because a specification describes a platform rather than a client: one recording
 * serves every tenant. Read-only here for the same reason — a workspace able to edit these would be editing
 * every other workspace's checks, and that is a decision for whoever maintains the product rather than for
 * whoever happens to be an owner.
 *
 * The rows are seeded, with the page each number was read on and the date somebody read it. Where a
 * placement has no row, `find()` returns null and the caller reports `no_spec` — which is the honest answer
 * for most placements and is itself useful. See docs/CREATIVE.md for why there is no API to read these from.
 */
final readonly class EloquentCreativeSpecRegistry implements CreativeSpecRegistry
{
    public function find(string $provider, string $placement, AssetKind $kind): ?CreativeSpec
    {
        $row = DB::table('creative_specs')
            ->where('provider', $provider)
            ->where('placement', $placement)
            ->where('kind', $kind->value)
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $values */
        $values = (array) $row;

        return $this->spec($values);
    }

    public function all(): array
    {
        $specs = [];

        foreach (DB::table('creative_specs')->orderBy('provider')->orderBy('placement')->get() as $row) {
            /** @var array<string, mixed> $values */
            $values = (array) $row;

            $specs[] = $this->spec($values);
        }

        return $specs;
    }

    /** @param array<string, mixed> $values */
    private function spec(array $values): CreativeSpec
    {
        return new CreativeSpec(
            provider: (string) ($values['provider'] ?? ''),
            placement: (string) ($values['placement'] ?? ''),
            kind: AssetKind::tryFrom((string) ($values['kind'] ?? '')) ?? AssetKind::Image,
            bounds: $this->bounds($values['bounds'] ?? null),
            provenance: new SpecProvenance(
                sourceUrl: (string) ($values['source_url'] ?? ''),
                documentedAt: $this->date($values['documented_at'] ?? null),
                recordedBy: is_string($values['recorded_by'] ?? null) ? (string) $values['recorded_by'] : null,
                note: is_string($values['note'] ?? null) ? (string) $values['note'] : null,
            ),
        );
    }

    /**
     * The stored bounds, read defensively.
     *
     * A row whose JSON does not parse yields **no bounds** rather than a partial set: a half-read
     * specification would check some requirements and silently skip others, and the report would say `pass`
     * for an asset nobody fully checked.
     *
     * @return array<string, SpecBound>
     */
    private function bounds(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($decoded)) {
            return [];
        }

        $bounds = [];

        foreach ($decoded as $requirement => $bound) {
            if (! is_string($requirement) || SpecRequirement::tryFrom($requirement) === null || ! is_array($bound)) {
                continue;
            }

            /** @var array<string, mixed> $bound */
            $allowed = $bound['allowed'] ?? null;

            $bounds[$requirement] = new SpecBound(
                min: isset($bound['min']) && is_numeric($bound['min']) ? (float) $bound['min'] : null,
                max: isset($bound['max']) && is_numeric($bound['max']) ? (float) $bound['max'] : null,
                allowed: is_array($allowed) ? $this->strings($allowed) : null,
                tolerance: isset($bound['tolerance']) && is_numeric($bound['tolerance'])
                    ? (float) $bound['tolerance']
                    : 0.0,
            );
        }

        return $bounds;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                $strings[] = trim($value);
            }
        }

        return $strings;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                return new DateTimeImmutable($value);
            } catch (Exception) {
                /*
                 * The epoch, which is obviously stale rather than plausibly current. A specification whose
                 * date cannot be read must not be treated as fresh.
                 */
                return new DateTimeImmutable('@0');
            }
        }

        return new DateTimeImmutable('@0');
    }
}
