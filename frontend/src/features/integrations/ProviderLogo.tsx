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
};

export function ProviderLogo({
  provider,
  className,
}: {
  provider: ProviderSlug;
  className?: string;
}) {
  const mark = MARKS[provider];

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
