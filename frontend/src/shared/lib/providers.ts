/** The names a person knows the advertising platforms by. Used where only a slug is to hand. */
const NAMES: Record<string, string> = {
  meta: 'Meta Ads',
  google_ads: 'Google Ads',
  tiktok: 'TikTok Ads',
  snapchat: 'Snapchat Ads',
  x: 'X Ads',
  linkedin: 'LinkedIn Ads',
  openai: 'ChatGPT Ads',
};

export function providerName(slug: string): string {
  return NAMES[slug] ?? slug.replace(/_/g, ' ');
}

/** The platform's identifier colour, as a design token — for a chip or a legend swatch, never a surface. */
export function providerColour(slug: string): string {
  const key = slug === 'google_ads' ? 'google' : slug === 'openai' ? 'openai' : slug;

  return `var(--ds-platform-${key})`;
}
