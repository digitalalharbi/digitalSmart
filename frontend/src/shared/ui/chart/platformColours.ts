/*
|--------------------------------------------------------------------------
| Advertising platform colours
|--------------------------------------------------------------------------
|
| The one place the six platforms' chart colours are named. The values live in tokens.css
| (`--ds-platform-*`, the approved brand values); this
| module only says which token belongs to which provider and which marks need an edge.
|
| Colour follows the platform, never its rank in a list, so filtering a chart does not repaint the
| platforms that remain. A provider outside the six falls back to the neutral series scale by the
| position it was given.
|
*/

/** The order platforms are listed in: Snapchat first, then TikTok, then Meta — the owner's priority. */
export const PLATFORM_ORDER = [
  'snapchat',
  'tiktok',
  'meta',
  'google_ads',
  'x',
  'linkedin',
] as const;

export type ChartPlatform = (typeof PLATFORM_ORDER)[number];

const TOKEN: Record<ChartPlatform, string> = {
  meta: '--ds-platform-meta',
  google_ads: '--ds-platform-google',
  snapchat: '--ds-platform-snapchat',
  tiktok: '--ds-platform-tiktok',
  linkedin: '--ds-platform-linkedin',
  x: '--ds-platform-x',
};

/**
 * Marks whose approved colour is close to one of the two surfaces and so needs an edge to be seen:
 * Google's near-white on light, Snapchat's and X's deep tones on dark.
 */
const EDGED = new Set<string>(['google_ads', 'snapchat', 'x']);

function known(provider: string): provider is ChartPlatform {
  return (PLATFORM_ORDER as readonly string[]).includes(provider);
}

/** A CSS colour for a provider, or `null` when it is not one of the six. */
export function platformChartColour(provider: string): string | null {
  return known(provider) ? `var(${TOKEN[provider]})` : null;
}

/** The colour for a provider, falling back to the neutral series scale by `index` for any other. */
export function platformSeriesColour(provider: string, index: number): string {
  return platformChartColour(provider) ?? `var(--ds-chart-${String(Math.min(index, 5) + 3)})`;
}

/** The outline for a provider's mark, or `undefined` when its colour reads on both surfaces. */
export function platformOutline(provider: string): string | undefined {
  return EDGED.has(provider) ? 'var(--ds-platform-edge)' : undefined;
}
