<?php

declare(strict_types=1);

use App\Domain\Integration\Provider;
use App\Domain\Integration\ProviderMcp;

/*
 * Which platform MCP servers are official, by their own documentation, and where each operation is routed.
 */

it('records only platforms whose own documentation describes an MCP server, each with its source', function (): void {
    foreach ([Provider::GoogleAds, Provider::Snapchat, Provider::Meta, Provider::TikTok] as $provider) {
        $mcp = ProviderMcp::of($provider);

        expect($mcp['trust'])->toBe('official')
            ->and($mcp['source'])->toStartWith('https://')
            ->and(parse_url((string) $mcp['source'], PHP_URL_HOST))->toBeIn(['developers.google.com', 'developers.snap.com', 'developers.facebook.com', 'ads.tiktok.com']);
    }

    foreach ([Provider::LinkedIn, Provider::X, Provider::OpenAI] as $provider) {
        expect(ProviderMcp::of($provider)['trust'])->toBe('none');
    }

    expect(ProviderMcp::of(Provider::GoogleAds)['writes'])->toBe('none')
        ->and(ProviderMcp::of(Provider::Snapchat)['writes'])->toBe('planned');
});

it('reads through a connected official MCP server, and directly otherwise', function (): void {
    expect(ProviderMcp::route(Provider::GoogleAds, 'read', true, false)['route'])->toBe('mcp')
        ->and(ProviderMcp::route(Provider::GoogleAds, 'read', false, false)['route'])->toBe('direct_api')
        // A platform with no official server is read directly even if something claiming to be one is "connected".
        ->and(ProviderMcp::route(Provider::LinkedIn, 'read', true, false))->toBe(['route' => 'direct_api', 'reason' => 'no_official_mcp']);
});

it('never writes through MCP: a write goes to the documented direct API or nowhere', function (): void {
    expect(ProviderMcp::route(Provider::Meta, 'write', true, true))->toBe(['route' => 'direct_api', 'reason' => 'direct_write_documented_reconciled'])
        ->and(ProviderMcp::route(Provider::TikTok, 'write', true, false))->toBe(['route' => 'unavailable', 'reason' => 'mcp_write_not_enabled'])
        ->and(ProviderMcp::route(Provider::X, 'write', false, false))->toBe(['route' => 'unavailable', 'reason' => 'no_documented_write']);
});
