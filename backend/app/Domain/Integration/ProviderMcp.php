<?php

declare(strict_types=1);

namespace App\Domain\Integration;

/**
 * What each platform's own MCP server is, as its official documentation says — checked on the date recorded.
 *
 * Only a platform's own developer documentation counts. A blog post, a directory listing or a community server is not
 * evidence of anything, and a platform with no official page here is `none`. Write capability is recorded as the
 * official page describes it, but this product does not route a write through MCP in this release: its read-back
 * reconciliation is built on the direct APIs, so a change made through MCP could not be confirmed.
 */
final class ProviderMcp
{
    public const string CHECKED_ON = '2026-10-19';

    /** @return array{trust: 'official'|'none', endpoint: string|null, reads: bool, writes: 'documented'|'planned'|'none', source: string|null} */
    public static function of(Provider $provider): array
    {
        return match ($provider) {
            /* "Read-only (current release)": list_accessible_customers, search (GAQL), get_resource_metadata. */
            Provider::GoogleAds => ['trust' => 'official', 'endpoint' => null, 'reads' => true, 'writes' => 'none', 'source' => 'https://developers.google.com/google-ads/api/docs/developer-toolkit/mcp-server'],
            /* Read-only; "Write access is planned for a future release." Per-member authorisation after admin approval. */
            Provider::Snapchat => ['trust' => 'official', 'endpoint' => 'https://mcp.snapchat.com/ads', 'reads' => true, 'writes' => 'planned', 'source' => 'https://developers.snap.com/marketing-api/Ads-MCP/Introduction'],
            /* Meta-hosted; "Create and edit ads, ad sets, and campaigns." */
            Provider::Meta => ['trust' => 'official', 'endpoint' => 'https://mcp.facebook.com/ads', 'reads' => true, 'writes' => 'documented', 'source' => 'https://developers.facebook.com/documentation/ads-commerce/ads-ai-connectors/ads-mcp-server/ads-mcp-server-overview'],
            /* TikTok for Business MCP server; tools include create campaign, pause ad group, budget changes. */
            Provider::TikTok => ['trust' => 'official', 'endpoint' => 'https://business-api.tiktok.com/open_mcp/tt-ads-mcp-layer', 'reads' => true, 'writes' => 'documented', 'source' => 'https://ads.tiktok.com/resources/help/article/about-tiktok-for-business-mcp-server'],
            default => ['trust' => 'none', 'endpoint' => null, 'reads' => false, 'writes' => 'none', 'source' => null],
        };
    }

    /**
     * Where one operation goes. Reads go to the platform's official MCP server only when the workspace has connected
     * it; otherwise to the direct API. Writes go to the direct API when its write is documented, and nowhere otherwise.
     *
     * @param  'read'|'write'  $operation
     * @return array{route: 'mcp'|'direct_api'|'unavailable', reason: string}
     */
    public static function route(Provider $provider, string $operation, bool $mcpConnected, bool $directWriteDocumented): array
    {
        $mcp = self::of($provider);

        if ($operation === 'read') {
            return $mcp['trust'] === 'official' && $mcp['reads'] && $mcpConnected
                ? ['route' => 'mcp', 'reason' => 'official_mcp_connected']
                : ['route' => 'direct_api', 'reason' => $mcp['trust'] === 'official' ? 'mcp_not_connected' : 'no_official_mcp'];
        }

        if ($directWriteDocumented) {
            return ['route' => 'direct_api', 'reason' => 'direct_write_documented_reconciled'];
        }

        return ['route' => 'unavailable', 'reason' => $mcp['writes'] === 'documented' ? 'mcp_write_not_enabled' : 'no_documented_write'];
    }
}
