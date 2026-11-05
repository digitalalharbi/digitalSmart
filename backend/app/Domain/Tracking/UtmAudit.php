<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

/**
 * What is wrong with a set of links' campaign parameters, judged from the parameters alone.
 *
 * Every finding is a fact about what was typed, never a guess about what was meant: a missing parameter, a value
 * whose letter case or spacing makes an analytics property file one campaign under two names, a destination that
 * already carries the same parameter (two answers to one question), and two live links in one project carrying the
 * identical set — which an analytics property cannot tell apart, so their visits arrive as one.
 *
 * It does not judge whether a source "matches" a platform or whether a campaign name is a good one. Those are
 * conventions an agency chooses, and a rule here would be an opinion presented as a defect.
 */
final class UtmAudit
{
    public const string MISSING_SOURCE = 'missing_source';

    public const string MISSING_MEDIUM = 'missing_medium';

    public const string MISSING_CAMPAIGN = 'missing_campaign';

    public const string MIXED_CASE = 'mixed_case';

    public const string HAS_SPACES = 'has_spaces';

    public const string DESTINATION_HAS_PARAMETER = 'destination_has_parameter';

    public const string DUPLICATE_SET = 'duplicate_set';

    private const array REQUIRED = [
        'utm_source' => self::MISSING_SOURCE,
        'utm_medium' => self::MISSING_MEDIUM,
        'utm_campaign' => self::MISSING_CAMPAIGN,
    ];

    private const array KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    /**
     * @param  list<array{id: string, project_id: string, live: bool, destination_url: string, utm: array<string, string>}>  $links
     * @return array<string, list<array{code: string, parameters: list<string>, shared_with: list<string>}>> keyed by link id; a clean link is absent
     */
    public static function judge(array $links): array
    {
        $sets = [];

        foreach ($links as $link) {
            $signature = self::signature($link['utm']);

            // Only live links collide: a paused or archived one sends no visits to be confused with another's.
            if ($link['live'] && $signature !== null) {
                $sets[$link['project_id'].'|'.$signature][] = $link['id'];
            }
        }

        $findings = [];

        foreach ($links as $link) {
            $found = [];
            $utm = $link['utm'];

            foreach (self::REQUIRED as $key => $code) {
                if (trim($utm[$key] ?? '') === '') {
                    $found[] = ['code' => $code, 'parameters' => [$key], 'shared_with' => []];
                }
            }

            $mixed = [];
            $spaced = [];

            foreach (self::KEYS as $key) {
                $value = $utm[$key] ?? '';

                if ($value !== mb_strtolower($value)) {
                    $mixed[] = $key;
                }

                if (preg_match('/\s/u', $value) === 1) {
                    $spaced[] = $key;
                }
            }

            if ($mixed !== []) {
                $found[] = ['code' => self::MIXED_CASE, 'parameters' => $mixed, 'shared_with' => []];
            }

            if ($spaced !== []) {
                $found[] = ['code' => self::HAS_SPACES, 'parameters' => $spaced, 'shared_with' => []];
            }

            $already = self::parametersIn($link['destination_url']);

            if ($already !== []) {
                $found[] = ['code' => self::DESTINATION_HAS_PARAMETER, 'parameters' => $already, 'shared_with' => []];
            }

            $signature = self::signature($utm);
            $twins = $link['live'] && $signature !== null
                ? array_values(array_diff($sets[$link['project_id'].'|'.$signature] ?? [], [$link['id']]))
                : [];

            if ($twins !== []) {
                $found[] = ['code' => self::DUPLICATE_SET, 'parameters' => [], 'shared_with' => $twins];
            }

            if ($found !== []) {
                $findings[$link['id']] = $found;
            }
        }

        return $findings;
    }

    /**
     * The set as an analytics property would compare it, or null when there is nothing to compare.
     *
     * Exact, not case-folded: `Spring` and `spring` are two campaigns to the property, which is the mixed-case
     * finding's whole point, so they are not also reported as one set.
     *
     * @param  array<string, string>  $utm
     */
    private static function signature(array $utm): ?string
    {
        $parts = array_map(static fn (string $key): string => trim($utm[$key] ?? ''), self::KEYS);

        return implode('', $parts) === '' ? null : implode("\x1f", $parts);
    }

    /** @return list<string> the campaign parameters the destination's own query already carries */
    private static function parametersIn(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $pairs);

        return array_values(array_intersect(self::KEYS, array_map('strval', array_keys($pairs))));
    }
}
