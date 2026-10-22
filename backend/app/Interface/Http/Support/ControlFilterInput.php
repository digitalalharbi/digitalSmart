<?php

declare(strict_types=1);

namespace App\Interface\Http\Support;

use App\Application\Shared\Contract\ActorContext;
use BackedEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * How the campaign, ad-group and ad screens read what they are narrowed to.
 *
 * One implementation, because three screens that parse "mine" or an unknown status differently would show the same
 * reader three different populations under one filter bar.
 */
final class ControlFilterInput
{
    /**
     * Read repeated enum parameters, or `false` when one of them is not a member.
     *
     * `false` rather than silently dropping it: a filter the server did not understand returns a page the
     * reader believes is narrowed and is not, which is worse than an error.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return list<T>|false
     */
    public static function enums(Request $request, string $key, string $enum): array|false
    {
        $values = [];

        foreach (self::strings($request, $key) as $value) {
            $case = $enum::tryFrom($value);

            if ($case === null) {
                return false;
            }

            $values[] = $case;
        }

        return $values;
    }

    /** @return list<string> */
    public static function strings(Request $request, string $key): array
    {
        $raw = $request->query($key, $request->query($key.'s', []));
        $values = [];

        foreach (is_array($raw) ? $raw : [$raw] as $value) {
            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The projects the page is narrowed to.
     *
     * `mine=1` means *the ones I am assigned to*, which for somebody who can see every project is a real
     * narrowing and for somebody scoped is a no-op. An empty assignment is **none**, not "no filter":
     * collapsing the two would show a newly-added media buyer the whole agency under a "My campaigns" title.
     *
     * @return list<string>
     */
    public static function projectScope(Request $request, ActorContext $actor): array
    {
        $explicit = self::uuids($request, 'project_id');

        if (! $request->boolean('mine')) {
            return $explicit;
        }

        $mine = $actor->assignedProjectIds();

        if ($mine === []) {
            return ['00000000-0000-0000-0000-000000000000'];
        }

        if ($explicit === []) {
            return $mine;
        }

        $both = array_values(array_intersect($explicit, $mine));

        return $both === [] ? ['00000000-0000-0000-0000-000000000000'] : $both;
    }

    /** @return list<string> */
    public static function uuids(Request $request, string $key): array
    {
        return array_values(array_filter(
            self::strings($request, $key),
            static fn (string $value): bool => preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
                $value,
            ) === 1,
        ));
    }

    /** @param list<string> $allowed */
    public static function unknown(string $field, array $allowed): JsonResponse
    {
        return response()->json([
            'message' => sprintf('Unknown %s.', str_replace('_', ' ', $field)),
            'code' => 'invalid_'.$field,
            'allowed' => $allowed,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function intOrNull(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
