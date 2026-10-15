import { useTranslation } from 'react-i18next';
import { formatMinor } from '@/shared/lib/format';
import { Sparkline } from '@/shared/ui/sparkline';
import type { CampaignControlPage } from './types';

/**
 * What the matched campaigns have spent, with the shape of it.
 *
 * One block per currency, because two currencies are two figures — a total across them would be a number with no
 * unit. Describes everything that matched the filters, not the page of cards under it.
 */
export function SpendHero({ meta, locale }: { meta: CampaignControlPage['meta']; locale: string }) {
  const { t } = useTranslation();

  if (meta.spend_by_currency.length === 0) {
    return null;
  }

  return (
    <section
      aria-label={t('campaignControl.spend.title')}
      className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
    >
      {meta.spend_by_currency.map((entry) => {
        const trend = meta.spend_trend.find((s) => s.currency === entry.currency)?.points ?? [];

        return (
          <div
            key={entry.currency}
            className="bg-surface border-line-subtle flex flex-col gap-2 rounded-2xl border p-4 shadow-xs"
          >
            <span className="ds-overline text-content-tertiary">
              {t('campaignControl.spend.title')} · {entry.currency}
            </span>
            <span className="ds-figure text-content text-3xl">
              {formatMinor(entry.spend_minor, entry.currency, locale)}
            </span>
            <Sparkline
              stretch
              values={trend}
              label={t('campaignControl.spend.trend', { currency: entry.currency })}
              className="h-14 w-full"
            />
          </div>
        );
      })}
    </section>
  );
}
