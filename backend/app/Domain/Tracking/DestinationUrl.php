<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

use App\Domain\Tracking\Exception\UnsafeDestination;

/**
 * Where a tracking link sends a visitor, checked once when the link is saved.
 *
 * **A tracking link is a redirect, and an unchecked redirect is somebody else's phishing page with this product's
 * name on it.** Two defences, and both are needed: the destination is fixed when the link is *saved* and never read
 * from the request that follows it (so a visitor cannot choose where a link goes), and what is saved has to look like
 * a public web page — http or https, a real host, no credentials in front of it, nothing aimed at this machine or the
 * private network behind it.
 */
final readonly class DestinationUrl
{
    private function __construct(public string $value, public string $host) {}

    /** @param  list<string>  $ownHosts hosts of this installation, which a link must not point back at */
    public static function parse(string $raw, array $ownHosts = []): self
    {
        $url = trim($raw);

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw UnsafeDestination::because('The destination must be one web address with no spaces.');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw UnsafeDestination::because('The destination must be a full web address, starting with https://.');
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw UnsafeDestination::because('Only http and https destinations are allowed.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw UnsafeDestination::because('A destination cannot carry a user name or password.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if (self::isPrivate($host) || in_array($host, array_map('strtolower', $ownHosts), true)) {
            throw UnsafeDestination::because('The destination must be a public website other than this application.');
        }

        return new self($url, $host);
    }

    /**
     * The destination with parameters added, never replacing one the page already carries.
     *
     * @param  array<string, string>  $extra
     */
    public function with(array $extra): string
    {
        $extra = array_filter($extra, static fn (string $value): bool => $value !== '');

        if ($extra === []) {
            return $this->value;
        }

        $fragment = '';
        $url = $this->value;

        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        parse_str((string) (parse_url($url, PHP_URL_QUERY) ?? ''), $existing);

        $add = array_diff_key($extra, $existing);

        if ($add === []) {
            return $this->value;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($add, '', '&', PHP_QUERY_RFC3986).$fragment;
    }

    private static function isPrivate(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return true;
        }

        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        /* A name with no dot is a machine on a private network; a numeric-only host is an obfuscated address. */
        return ! str_contains($host, '.') || preg_match('/^[0-9.]+$/', $host) === 1 || preg_match('/^0x[0-9a-f]+$/i', $host) === 1;
    }
}
