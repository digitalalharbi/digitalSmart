<?php

declare(strict_types=1);

namespace App\Domain\Advertising;

/**
 * What can honestly be shown of a creative: a real image the platform or this product holds, or nothing.
 *
 * **There is no third option.** A creative with no usable preview is `none` and the interface draws a neutral
 * placeholder; this class never builds a stand-in, and it refuses the things that only look like a preview:
 *
 *  - a **sandbox fixture** address (`*.invalid`, `*.test`, `*.example`, `localhost`) — those are written into the fixtures
 *    precisely because they point at nothing, and drawing one would be a fake asset with a broken-image icon on it;
 *  - an address that **carries a credential** in its query (`token`, `signature`, `sig`, `key`, …) — a signed address
 *    belongs to whoever it was signed for, expires, and must not travel into a client's report;
 *  - anything that is not `https://` or an app-relative path — no `javascript:`, no `data:`, no plain `http:`.
 *
 * `origin` says where the bytes live: `app` for a path this product serves, `provider` for the platform's own host. The
 * interface loads a provider image with no referrer, so a client opening a report does not announce which one.
 */
final readonly class CreativeMedia
{
    public const string AVAILABLE = 'available';

    public const string FIXTURE = 'fixture';

    public const string NONE = 'none';

    /** Query parameters that mean "this address is a credential". Matched whole-word, case-insensitively. */
    private const array CREDENTIAL_PARAMETERS = [
        'token', 'access_token', 'auth', 'authorization', 'signature', 'sig', 'key', 'apikey', 'api_key',
        'secret', 'password', 'x-amz-signature', 'x-amz-credential', 'x-goog-signature', 'x-goog-credential',
        'expires', 'se',
    ];

    private const array FIXTURE_SUFFIXES = ['.invalid', '.test', '.example', '.localhost', '.local'];

    private function __construct(
        public string $state,
        public string $kind,
        public ?string $url,
        public ?string $origin,
    ) {}

    /**
     * The best preview among the candidates, in the order given, for a creative of this format.
     *
     * Candidates are tried in order: the creative's own thumbnail, then its first image asset. An address is only ever
     * used whole — never edited into a better one — and the first that passes is the preview.
     */
    public static function resolve(string $format, ?string ...$candidates): self
    {
        $sawFixture = false;

        foreach ($candidates as $candidate) {
            if ($candidate === null || trim($candidate) === '') {
                continue;
            }

            $verdict = self::judge(trim($candidate));

            if ($verdict === self::FIXTURE) {
                $sawFixture = true;

                continue;
            }

            if ($verdict === self::AVAILABLE) {
                $url = trim($candidate);

                return new self(
                    self::AVAILABLE,
                    $format === CreativeFormat::Video->value ? 'video' : 'image',
                    $url,
                    str_starts_with($url, '/') ? 'app' : 'provider',
                );
            }
        }

        return new self($sawFixture ? self::FIXTURE : self::NONE, 'none', null, null);
    }

    public function isAvailable(): bool
    {
        return $this->state === self::AVAILABLE;
    }

    /** @return 'available'|'fixture'|'none'|'rejected' */
    private static function judge(string $url): string
    {
        // An app-relative path, and only that: `//host/x` is a protocol-relative address to somebody else's server.
        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') ? 'rejected' : self::AVAILABLE;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || strtolower($parts['scheme']) !== 'https') {
            return 'rejected';
        }

        $host = strtolower($parts['host']);

        foreach (self::FIXTURE_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return self::FIXTURE;
            }
        }

        if ($host === 'localhost') {
            return self::FIXTURE;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'rejected';
        }

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);

            foreach (array_keys($query) as $name) {
                if (in_array(strtolower((string) $name), self::CREDENTIAL_PARAMETERS, true)) {
                    return 'rejected';
                }
            }
        }

        return self::AVAILABLE;
    }
}
