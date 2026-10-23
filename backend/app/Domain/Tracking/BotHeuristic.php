<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

/**
 * A conservative guess at whether a visit was a program.
 *
 * **It is a guess and is documented as one.** It looks at the user-agent string only: an empty one, or one that names a
 * known crawler, link-preview fetcher or HTTP library. It does not fingerprint, does not look at addresses, and misses
 * any bot that dresses as a browser. A false positive is cheap (the event is kept, marked, and a reader can include
 * it); a false negative inflates a count — which is why the mark is never a deletion and never a certainty.
 */
final class BotHeuristic
{
    private const string PATTERN = '/bot\b|crawl|spider|slurp|facebookexternalhit|facebot|whatsapp|telegrambot|twitterbot|linkedinbot|'
        .'snapchat|bytespider|preview|monitor|uptime|headless|phantomjs|lighthouse|python-requests|curl\/|wget|go-http-client|'
        .'java\/|okhttp|libwww|httpclient|axios|node-fetch|scrapy/i';

    public static function classify(?string $userAgent): TrafficClass
    {
        $agent = trim((string) $userAgent);

        if ($agent === '' || strlen($agent) < 12) {
            return TrafficClass::SuspectedBot;
        }

        return preg_match(self::PATTERN, $agent) === 1 ? TrafficClass::SuspectedBot : TrafficClass::Valid;
    }

    /** A coarse class — never a model number or a version, which would be fingerprinting. */
    public static function deviceClass(?string $userAgent): string
    {
        $agent = strtolower((string) $userAgent);

        return match (true) {
            $agent === '' => 'unknown',
            str_contains($agent, 'ipad') || str_contains($agent, 'tablet') => 'tablet',
            str_contains($agent, 'mobi') || str_contains($agent, 'iphone') || str_contains($agent, 'android') => 'mobile',
            default => 'desktop',
        };
    }

    public static function browserClass(?string $userAgent): string
    {
        $agent = strtolower((string) $userAgent);

        return match (true) {
            $agent === '' => 'unknown',
            str_contains($agent, 'edg/') => 'edge',
            str_contains($agent, 'firefox') || str_contains($agent, 'fxios') => 'firefox',
            str_contains($agent, 'chrome') || str_contains($agent, 'crios') => 'chrome',
            str_contains($agent, 'safari') => 'safari',
            default => 'other',
        };
    }
}
