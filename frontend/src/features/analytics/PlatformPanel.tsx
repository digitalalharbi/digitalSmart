import { localeTag } from '@/shared/lib/format';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { formatMetric, MISSING } from '@/shared/metrics';
import type { AnalyticsPayload, BreakdownRowPayload } from '@/shared/metrics';
import { DonutChart } from '@/shared/ui/viz';

import { platformOutline, platformSeriesColour } from '@/shared/ui/chart/platformColours';

/** The figures a platform card carries, in the order they are read — each only if the platform reports it. */
const FIGURES = ['conversions', 'cpa', 'roas', 'ctr'] as const;

/**
 * Where the money went and what it bought, one card per platform.
 *
 * A ring for the share of spend, a card for each platform's own figures. Spend is the one measure that is a real
 * whole — what each platform took out of a total — so it is the only thing drawn as a share; a platform's ROAS is a
 * ratio and is shown as a figure. A platform that does not report a figure shows a dash, never a zero.
 *
 * **A card is a filter.** Choosing one narrows the rest of the page to that platform, and choosing it again lets go.
 */
export function PlatformPanel({
  data,
  selected,
  onToggle,
}: {
  data: AnalyticsPayload;
  selected: string[];
  onToggle: (provider: string) => void;
}) {
  const { t, i18n } = useTranslation();
  const rows = data.breakdown;

  if (rows.length < 2) {
    return null;
  }

  const spendOf = (row: BreakdownRowPayload): number => row.values['spend']?.value ?? 0;
  const total = rows.reduce((sum, row) => sum + spendOf(row), 0);
  /* A share of a total made of two currencies is not a share of anything. */
  const currencies = new Set(rows.map((row) => row.currency));
  const canShare = total > 0 && currencies.size === 1;
  const top = rows.slice(0, 6);

  return (
    <section aria-label={t('analytics.platforms.title')} className="flex flex-col gap-3">
      <h2 className="text-content text-[0.9375rem] font-semibold">
        {t('analytics.platforms.title')}
      </h2>
      <div
        className={cn(
          'grid gap-4',
          /* Without a ring — a total of two currencies is not a whole — the cards have the whole row. */
          canShare && 'lg:grid-cols-[minmax(0,16rem)_minmax(0,1fr)]',
        )}
      >
        {canShare ? (
          <DonutChart
            label={t('analytics.platforms.share')}
            segments={top.map((row, index) => ({
              key: row.key,
              label: row.label,
              value: spendOf(row),
              colour: platformSeriesColour(row.key, index),
              outline: platformOutline(row.key),
            }))}
            centre={{
              value: new Intl.NumberFormat(localeTag(i18n.language), {
                style: 'percent',
                maximumFractionDigits: 0,
              }).format(spendOf(top[0] as BreakdownRowPayload) / total),
              caption: top[0]?.label ?? '',
            }}
            format={(value) =>
              new Intl.NumberFormat(localeTag(i18n.language), {
                style: 'percent',
                maximumFractionDigits: 0,
              }).format(value / total)
            }
          />
        ) : null}

        <ul
          aria-label={t('analytics.platforms.cards')}
          className={cn('grid gap-3 sm:grid-cols-2', !canShare && 'xl:grid-cols-3')}
        >
          {top.map((row, index) => {
            const isOn = selected.includes(row.key);
            const spend = row.values['spend'];

            return (
              <li key={row.key} className="min-w-0">
                <button
                  type="button"
                  aria-pressed={isOn}
                  onClick={() => onToggle(row.key)}
                  className={cn(
                    'border-line-subtle bg-surface flex h-full w-full min-w-0 flex-col gap-3 rounded-2xl border p-4 text-start transition',
                    isOn ? 'border-accent-strong bg-accent-subtle/40' : 'hover:bg-surface-sunken',
                  )}
                >
                  <span className="flex items-center gap-2">
                    <span
                      aria-hidden
                      className="size-2.5 shrink-0 rounded-full"
                      style={{
                        backgroundColor: platformSeriesColour(row.key, index),
                        ...(platformOutline(row.key) === undefined
                          ? {}
                          : { boxShadow: `0 0 0 1px ${platformOutline(row.key) ?? ''}` }),
                      }}
                    />
                    <span className="text-content min-w-0 flex-1 truncate text-sm font-semibold">
                      {row.label}
                    </span>
                    <span className="ds-figure text-content text-sm">
                      {spend === undefined ? MISSING : formatMetric(spend, i18n.language, true)}
                    </span>
                  </span>

                  {canShare ? (
                    <span className="bg-surface-sunken block h-1.5 overflow-hidden rounded-full">
                      <span
                        className="bg-accent-strong block h-full rounded-full"
                        style={{ width: `${String(Math.round((spendOf(row) / total) * 100))}%` }}
                      />
                    </span>
                  ) : null}

                  <span className="grid grid-cols-2 gap-x-3 gap-y-1.5">
                    {FIGURES.map((key) => {
                      const value = row.values[key];

                      return (
                        <span key={key} className="flex min-w-0 flex-col">
                          <span className="text-content-tertiary ds-overline truncate">
                            {value?.label ?? key}
                          </span>
                          <span className="ds-numeric text-content text-sm">
                            {value === undefined
                              ? MISSING
                              : formatMetric(value, i18n.language, true)}
                          </span>
                        </span>
                      );
                    })}
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      </div>
    </section>
  );
}
