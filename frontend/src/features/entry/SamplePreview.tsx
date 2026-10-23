import { useTranslation } from 'react-i18next';
import { TriangleAlert } from 'lucide-react';
import { platformOutline, platformSeriesColour } from '@/shared/ui/chart/platformColours';
import { DonutChart } from '@/shared/ui/viz';
import { Sparkline } from '@/shared/ui/viz/Sparkline';
import { cn } from '@/shared/lib/cn';

/*
 * An illustration of the product, drawn from its own parts. Every figure is invented for the picture and the
 * panel says so; nothing here is read from an account.
 */
const MIX = [
  { key: 'snapchat', label: 'Snapchat', value: 38 },
  { key: 'tiktok', label: 'TikTok', value: 27 },
  { key: 'meta', label: 'Meta', value: 18 },
  { key: 'google_ads', label: 'Google', value: 12 },
  { key: 'linkedin', label: 'LinkedIn', value: 5 },
];

const TOP = [
  { name: 'Ramadan — Sales', provider: 'snapchat', roas: 4.2, share: 100 },
  { name: 'Always-on Video', provider: 'tiktok', roas: 3.6, share: 82 },
  { name: 'Spring Collection', provider: 'meta', roas: 2.9, share: 64 },
];

const TREND = [14, 18, 16, 22, 21, 27, 25, 31, 29, 34, 33, 38];

function Kpi({
  label,
  value,
  highlight = false,
}: {
  label: string;
  value: string;
  highlight?: boolean;
}) {
  return (
    <div
      className={cn(
        'flex min-w-0 flex-col gap-1 rounded-2xl border p-3',
        highlight
          ? 'bg-accent-strong border-transparent text-white'
          : 'bg-surface-sunken border-line text-content',
      )}
    >
      <span
        className={cn(
          'truncate text-[0.6875rem]',
          highlight ? 'text-white' : 'text-content-secondary',
        )}
      >
        {label}
      </span>
      <span dir="ltr" className="ds-numeric ds-figure text-start text-lg leading-none">
        {value}
      </span>
    </div>
  );
}

export function SamplePreview({ compact = false }: { compact?: boolean }) {
  const { t } = useTranslation();

  return (
    <section
      aria-label={t('entry.preview.label')}
      className="bg-surface border-line flex min-h-0 flex-col gap-3 rounded-3xl border p-4 shadow-sm"
    >
      <header className="flex items-center justify-between gap-2">
        <h2 className="text-content text-sm font-semibold">{t('entry.preview.label')}</h2>
        <span className="bg-warning-surface text-warning rounded-full px-2.5 py-1 text-[0.6875rem] font-medium">
          {t('entry.preview.sample')}
        </span>
      </header>

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Kpi label={t('entry.preview.spend')} value="SAR 48.9K" highlight />
        <Kpi label={t('entry.preview.results')} value="1,556" />
        <Kpi label={t('entry.preview.roas')} value="3.4×" />
        <Kpi label={t('entry.preview.active')} value="16" />
      </div>

      <div className="grid min-h-0 gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
        <div className="bg-surface-sunken border-line flex flex-col gap-2 rounded-2xl border p-3">
          <h3 className="text-content-secondary text-xs font-medium">{t('entry.preview.mix')}</h3>
          <DonutChart
            label={t('entry.preview.mix')}
            size={compact ? 88 : 104}
            legend={false}
            segments={MIX.map((row, index) => ({
              key: row.key,
              label: row.label,
              value: row.value,
              colour: platformSeriesColour(row.key, index),
              outline: platformOutline(row.key),
            }))}
            centre={{ value: '5' }}
            className="justify-center"
          />
        </div>

        <div className="bg-surface-sunken border-line flex flex-col gap-2 rounded-2xl border p-3">
          <h3 className="text-content-secondary text-xs font-medium">{t('entry.preview.top')}</h3>
          <ol className="flex flex-col gap-2">
            {TOP.map((row) => (
              <li key={row.name} className="flex flex-col gap-1" dir="ltr">
                <span className="flex items-baseline justify-between gap-2 text-xs">
                  <span className="text-content truncate font-medium">{row.name}</span>
                  <span className="ds-numeric text-content-secondary shrink-0">
                    {row.roas.toFixed(1)}×
                  </span>
                </span>
                <span className="bg-surface block h-1.5 overflow-hidden rounded-full">
                  <span
                    className="block h-full rounded-full"
                    style={{
                      width: `${String(row.share)}%`,
                      backgroundColor: platformSeriesColour(row.provider, 0),
                    }}
                  />
                </span>
              </li>
            ))}
          </ol>
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
        <div className="bg-surface-sunken border-line flex items-center justify-between gap-3 rounded-2xl border p-3">
          <span className="text-content-secondary text-xs font-medium">
            {t('entry.preview.trend')}
          </span>
          <span className="text-accent-strong" dir="ltr">
            <Sparkline values={TREND} width={96} height={30} />
          </span>
        </div>
        <div className="bg-warning-surface text-warning flex items-center gap-2 rounded-2xl p-3 text-xs">
          <TriangleAlert aria-hidden className="size-4 shrink-0" />
          <span className="min-w-0">
            <span className="block font-semibold">{t('entry.preview.attention')}</span>
            <span className="block truncate">{t('entry.preview.attentionBody')}</span>
          </span>
        </div>
      </div>

      <p className="text-content-tertiary text-[0.6875rem]">{t('entry.preview.disclaimer')}</p>
    </section>
  );
}
