<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

/**
 * A pattern that turns a link's facts into its campaign parameters.
 *
 * `utm_source={{platform}}` is a template; `utm_source=snapchat` is what it makes. Only the documented variables are
 * replaced and anything else is left as written, so a typo shows in the generated link instead of vanishing — and a
 * variable with no value produces an empty parameter that is then dropped, never the literal word "null".
 */
final class LinkTemplate
{
    public const array VARIABLES = ['platform', 'client', 'project', 'campaign', 'ad_group', 'ad', 'creative'];

    /**
     * @param  array<string, string>  $patterns  utm parameter => pattern
     * @param  array<string, string|null>  $facts  variable => value
     * @return array<string, string>
     */
    public static function render(array $patterns, array $facts): array
    {
        $out = [];

        foreach ($patterns as $parameter => $pattern) {
            $value = trim((string) preg_replace_callback(
                '/\{\{\s*([a-z_]+)\s*\}\}/',
                static function (array $m) use ($facts): string {
                    if (! in_array($m[1], self::VARIABLES, true)) {
                        return $m[0];
                    }

                    return self::slug($facts[$m[1]] ?? null);
                },
                $pattern,
            ));

            if ($value !== '') {
                $out[$parameter] = $value;
            }
        }

        return $out;
    }

    /** Lower case, words joined with underscores, Arabic kept as it is — a campaign name is a label, not an identifier. */
    private static function slug(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $slug = mb_strtolower(trim($value));
        $slug = (string) preg_replace('/[\s\-]+/u', '_', $slug);

        return (string) preg_replace('/[^\p{L}\p{N}_.]/u', '', $slug);
    }
}
