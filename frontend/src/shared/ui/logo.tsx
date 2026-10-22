import horizontalDark from '@/assets/brand/logo/digitalsmart-horizontal-dark.svg';
import horizontalLight from '@/assets/brand/logo/digitalsmart-horizontal-light.svg';
import horizontalOnOrange from '@/assets/brand/logo/digitalsmart-horizontal-on-orange.svg';
import horizontalMono from '@/assets/brand/logo/digitalsmart-horizontal-mono-black.svg';
import stackedDark from '@/assets/brand/logo/digitalsmart-stacked-dark.svg';
import stackedLight from '@/assets/brand/logo/digitalsmart-stacked-light.svg';
import stackedOnOrange from '@/assets/brand/logo/digitalsmart-stacked-on-orange.svg';
import stackedMono from '@/assets/brand/logo/digitalsmart-stacked-mono-black.svg';
import markDark from '@/assets/brand/logo/digitalsmart-mark-dark.svg';
import markLight from '@/assets/brand/logo/digitalsmart-mark-light.svg';
import markOnOrange from '@/assets/brand/logo/digitalsmart-mark-on-orange.svg';
import markMono from '@/assets/brand/logo/digitalsmart-mark-mono-black.svg';
import { cn } from '@/shared/lib/cn';

/*
|--------------------------------------------------------------------------
| The digitalSmart logo
|--------------------------------------------------------------------------
|
| The approved artwork from the brand kit, shown as it was delivered. Each file is loaded as an
| image, so its paths are never re-typed, re-coloured or re-drawn here, and the logo is never
| made of text or CSS.
|
| Variants name the *background* the artwork is made for, as the kit does:
|   dark      – for dark surfaces (light wordmark)
|   light     – for light surfaces (dark wordmark)
|   on-orange – for the brand orange
|   mono      – one-colour black, for print and single-ink uses
|   auto      – dark or light, whichever the current theme calls for. Both are in the page; CSS
|               shows one, so a theme change never flashes the wrong logo.
|
| Minimum sizes from the brand rules: 100px wide for the horizontal and stacked lockups, 16px for
| the mark. A smaller request is raised to the minimum rather than shrinking the artwork.
|
*/

export type LogoVariant = 'auto' | 'dark' | 'light' | 'on-orange' | 'mono';
export type LogoLayout = 'horizontal' | 'stacked' | 'mark';

const ART: Record<LogoLayout, Record<Exclude<LogoVariant, 'auto'>, string>> = {
  horizontal: {
    dark: horizontalDark,
    light: horizontalLight,
    'on-orange': horizontalOnOrange,
    mono: horizontalMono,
  },
  stacked: {
    dark: stackedDark,
    light: stackedLight,
    'on-orange': stackedOnOrange,
    mono: stackedMono,
  },
  mark: { dark: markDark, light: markLight, 'on-orange': markOnOrange, mono: markMono },
};

/** Intrinsic proportions of each lockup, taken from the kit's own viewBoxes. */
const RATIO: Record<LogoLayout, number> = {
  horizontal: 568.7 / 160,
  stacked: 404.8 / 234.8,
  mark: 1,
};

export const LOGO_MINIMUM: Record<LogoLayout, number> = {
  horizontal: 100,
  stacked: 100,
  mark: 16,
};

interface LogoProps {
  variant?: LogoVariant;
  layout?: LogoLayout;
  /** Rendered width in pixels. Raised to the brand minimum when smaller. */
  width?: number;
  className?: string;
  /** The product name for assistive technology. Pass `null` when text beside the logo already says it. */
  label?: string | null;
}

export function Logo({
  variant = 'auto',
  layout = 'horizontal',
  width,
  className,
  label = 'digitalSmart',
}: LogoProps) {
  const minimum = LOGO_MINIMUM[layout];
  const size = width === undefined ? undefined : Math.max(width, minimum);
  const style =
    size === undefined ? undefined : { width: size, height: Math.round(size / RATIO[layout]) };
  const alt = label ?? '';
  const common = cn('block shrink-0 select-none', className);

  /* The artwork is directional-neutral: it is never mirrored for right-to-left. */
  if (variant !== 'auto') {
    return (
      <img
        src={ART[layout][variant]}
        alt={alt}
        style={style}
        className={common}
        draggable={false}
        data-logo={`${layout}-${variant}`}
      />
    );
  }

  return (
    <>
      <img
        src={ART[layout].dark}
        alt={alt}
        style={style}
        className={cn(common, 'ds-logo-for-dark')}
        draggable={false}
        data-logo={`${layout}-dark`}
      />
      <img
        src={ART[layout].light}
        alt={alt}
        style={style}
        className={cn(common, 'ds-logo-for-light')}
        draggable={false}
        data-logo={`${layout}-light`}
      />
    </>
  );
}
