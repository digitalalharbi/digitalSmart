import type { ProviderRow } from './types';

/**
 * Which fields to ask for when the server reports nothing missing.
 *
 * A configured provider returns an empty `missing_credentials`, but replacing
 * credentials still needs the full set — and the server never tells us the
 * values, only which keys it holds.
 */
export function credentialKeysFor(provider: ProviderRow): string[] {
  const BY_PROVIDER: Record<string, string[]> = {
    meta: ['client_id', 'client_secret'],
    google_ads: ['client_id', 'client_secret', 'developer_token'],
    tiktok: ['app_id', 'app_secret'],
    snapchat: ['client_id', 'client_secret'],
    x: ['consumer_key', 'consumer_secret'],
    linkedin: ['client_id', 'client_secret'],
    openai: ['api_key'],
  };

  return BY_PROVIDER[provider.provider] ?? [];
}
