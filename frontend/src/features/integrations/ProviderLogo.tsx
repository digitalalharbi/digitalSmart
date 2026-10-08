import { cn } from '@/shared/lib/cn';
import type { ProviderSlug } from './types';

/**
 * A small provider mark.
 *
 * Initials on the provider's identifier colour, not the platform's logo: this
 * is an identifier chip, and the design system keeps platform colour to exactly
 * this role rather than letting it spread across the interface.
 */
const MARKS: Record<ProviderSlug, { initials: string; token: string }> = {
  meta: { initials: 'M', token: '--ds-platform-meta' },
  google_ads: { initials: 'G', token: '--ds-platform-google' },
  tiktok: { initials: 'TT', token: '--ds-platform-tiktok' },
  snapchat: { initials: 'S', token: '--ds-platform-snapchat' },
  x: { initials: 'X', token: '--ds-platform-x' },
  linkedin: { initials: 'in', token: '--ds-platform-linkedin' },
  openai: { initials: 'AI', token: '--ds-platform-openai' },
  google_analytics: { initials: 'GA', token: '--ds-platform-google-analytics' },
  google_tag_manager: { initials: 'GT', token: '--ds-platform-google-tag-manager' },
  salla: { initials: 'SA', token: '--ds-platform-salla' },
  zid: { initials: 'Z', token: '--ds-platform-zid' },
  hubspot: { initials: 'HS', token: '--ds-platform-hubspot' },
};

/**
 * What to draw for a provider this build has never heard of.
 *
 * Not a hypothetical. The **server** owns the provider list, and adding one to it put a slug on this page that
 * this map had no entry for — so `MARKS[provider]` was undefined, reading `.token` off it threw, and the whole
 * integrations page became an error boundary. The union above is typed, which is exactly why it was trusted: a
 * server string cast into it is not checked by anything.
 *
 * So an unknown provider now renders as its own initials on a neutral chip. A new provider missing its colour is
 * a cosmetic gap somebody notices; a new provider taking the page down is an outage.
 */
function markFor(provider: string): { initials: string; token: string } {
  return (
    MARKS[provider as ProviderSlug] ?? {
      /* The slug's own first letters — `google_ads` becomes "GA" — which is at least the right provider. */
      initials: provider
        .split('_')
        .map((part) => part.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase(),
      token: '--ds-text-tertiary',
    }
  );
}

export function ProviderLogo({
  provider,
  className,
}: {
  provider: ProviderSlug;
  className?: string;
}) {
  const mark = markFor(provider);

  return (
    <span
      aria-hidden="true"
      className={cn(
        'grid size-9 shrink-0 place-items-center rounded-lg text-xs font-semibold text-white',
        className,
      )}
      style={{ backgroundColor: `var(${mark.token})` }}
    >
      {mark.initials}
    </span>
  );
}
