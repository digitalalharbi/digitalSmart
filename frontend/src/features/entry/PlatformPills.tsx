import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { platformChartColour } from '@/shared/ui/chart/platformColours';

/**
 * The platforms digitalSmart is built to connect to and analyse — names only, as small pills.
 *
 * This says what the product supports, not what is connected: nothing here claims a live account.
 */
const PLATFORMS = [
  { key: 'snapchat', name: 'Snapchat' },
  { key: 'tiktok', name: 'TikTok' },
  { key: 'meta', name: 'Meta' },
  { key: 'google_ads', name: 'Google' },
  { key: 'x', name: 'X' },
  { key: 'linkedin', name: 'LinkedIn' },
  { key: 'openai', name: 'ChatGPT' },
] as const;

/** `centered`: everywhere, or only below the desktop breakpoint, where the column is the whole screen. */
export function PlatformPills({ centered }: { centered?: 'always' | 'max-lg' }) {
  const centre =
    centered === 'always'
      ? 'items-center'
      : centered === 'max-lg'
        ? 'items-center lg:items-start'
        : null;
  const centreRow =
    centered === 'always'
      ? 'justify-center'
      : centered === 'max-lg'
        ? 'justify-center lg:justify-start'
        : null;
  const { t } = useTranslation();

  return (
    <div id="platforms" className={cn('flex flex-col gap-2', centre)}>
      <p className="text-content-tertiary text-xs">{t('entry.platformsLabel')}</p>
      <ul className={cn('flex flex-wrap gap-1.5', centreRow)}>
        {PLATFORMS.map((platform) => (
          <li
            key={platform.key}
            dir="ltr"
            className="border-line bg-surface text-content flex items-center gap-1.5 rounded-full border py-1 ps-2.5 pe-3 text-xs font-medium"
          >
            <span
              aria-hidden
              className="size-2 rounded-full"
              style={{
                backgroundColor: platformChartColour(platform.key) ?? 'var(--ds-text-tertiary)',
                boxShadow: '0 0 0 1px var(--ds-platform-edge)',
              }}
            />
            {platform.name}
          </li>
        ))}
      </ul>
    </div>
  );
}
