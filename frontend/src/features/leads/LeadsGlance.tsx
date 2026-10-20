import { useTranslation } from 'react-i18next';
import { formatNumber } from '@/shared/lib/format';
import { useUiStore } from '@/shared/store/ui-store';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { DonutChart, ProgressBar, type ProgressTone } from '@/shared/ui/viz';
import type { Lead, LeadFunnel, LeadSourceRow } from './types';

const SLICE = [
  'var(--ds-chart-1)',
  'var(--ds-chart-2)',
  'var(--ds-chart-3)',
  'var(--ds-chart-4)',
  'var(--ds-neutral-400)',
];

/** How quickly the people in the loaded pipeline were first answered. Only leads somebody answered have a time. */
const SPEED: { key: 'fast' | 'day' | 'slow'; max: number; tone: ProgressTone }[] = [
  { key: 'fast', max: 1, tone: 'good' },
  { key: 'day', max: 24, tone: 'watch' },
  { key: 'slow', max: Infinity, tone: 'bad' },
];

/**
 * The Lead Center at a glance: how good the leads are, where they come from, and how fast they were answered.
 * Three pictures instead of the paragraphs a CRM would write — each figure is a count somebody can verify, and
 * the words are only the labels.
 */
export function LeadsGlance({
  funnel,
  sources,
  leads,
}: {
  funnel: LeadFunnel;
  sources: LeadSourceRow[];
  leads: Lead[] | undefined;
}) {
  const { t } = useTranslation();
  const locale = useUiStore((state) => state.locale);
  const n = (value: number) => formatNumber(value, locale);

  const other = Math.max(0, funnel.total - funnel.qualified - funnel.disqualified);
  const qualityShare =
    funnel.qualified + funnel.disqualified === 0
      ? null
      : Math.round((funnel.qualified / (funnel.qualified + funnel.disqualified)) * 100);

  const ranked = [...sources].sort((a, b) => b.total - a.total);
  const top = ranked.slice(0, 4);
  const rest = ranked.slice(4).reduce((sum, row) => sum + row.total, 0);
  const sourceSegments = [
    ...top.map((row, index) => ({
      key: `${row.source_kind}:${row.source_detail ?? ''}`,
      label: row.source_detail ?? row.source_kind_label,
      value: row.total,
      colour: SLICE[index] ?? 'var(--ds-neutral-400)',
    })),
    ...(rest > 0
      ? [{ key: 'other', label: t('leads.glance.other'), value: rest, colour: SLICE[4] ?? '' }]
      : []),
  ];

  const answered = (leads ?? []).filter((lead) => lead.hours_to_first_contact !== null);
  const waiting = (leads ?? []).filter(
    (lead) => lead.first_contacted_at === null && !lead.is_closed,
  ).length;
  const speed = SPEED.map((band, index) => {
    const floor = index === 0 ? -1 : (SPEED[index - 1]?.max ?? 0);
    return {
      ...band,
      count: answered.filter((lead) => {
        const hours = lead.hours_to_first_contact ?? 0;
        return hours > floor && hours <= band.max;
      }).length,
    };
  });

  const reasons = new Map<string, number>();
  for (const lead of leads ?? []) {
    if (lead.disqualified_reason !== null && lead.disqualified_reason !== '') {
      reasons.set(lead.disqualified_reason, (reasons.get(lead.disqualified_reason) ?? 0) + 1);
    }
  }
  const topReasons = [...reasons.entries()].sort((a, b) => b[1] - a[1]).slice(0, 3);

  return (
    <section aria-label={t('leads.glance.label')} className="grid gap-4 lg:grid-cols-3">
      <Card>
        <CardHeader>
          <CardTitle>{t('leads.glance.quality')}</CardTitle>
        </CardHeader>
        <CardContent>
          <DonutChart
            size={120}
            label={t('leads.glance.quality')}
            centre={{ value: qualityShare === null ? '—' : `${qualityShare}%` }}
            segments={[
              {
                key: 'qualified',
                label: t('leads.board.stage.qualified'),
                value: funnel.qualified,
                colour: 'var(--ds-success-500)',
              },
              {
                key: 'disqualified',
                label: t('leads.board.stage.disqualified'),
                value: funnel.disqualified,
                colour: 'var(--ds-danger-500)',
              },
              {
                key: 'other',
                label: t('leads.glance.open'),
                value: other,
                colour: 'var(--ds-neutral-300)',
              },
            ]}
            format={n}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('leads.glance.sources')}</CardTitle>
        </CardHeader>
        <CardContent>
          <DonutChart
            size={120}
            label={t('leads.glance.sources')}
            centre={{ value: n(funnel.total) }}
            segments={sourceSegments}
            format={n}
          />
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('leads.glance.speed')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          {speed.map((band) => (
            <div key={band.key} className="flex items-center gap-3 text-xs">
              <span className="text-content-secondary w-20 shrink-0">
                {t(`leads.glance.speed_${band.key}`)}
              </span>
              <ProgressBar
                className="flex-1"
                value={band.count}
                max={Math.max(1, answered.length)}
                tone={band.tone}
                label={t(`leads.glance.speed_${band.key}`)}
              />
              <span className="ds-numeric text-content w-8 text-end font-medium">
                {n(band.count)}
              </span>
            </div>
          ))}
          <div className="border-line-subtle flex flex-wrap items-center gap-1.5 border-t pt-3">
            <span className="bg-surface-sunken text-content-secondary rounded-full px-2.5 py-1 text-xs font-semibold">
              {t('leads.glance.waiting', { count: waiting })}
            </span>
            {topReasons.map(([reason, count]) => (
              <span
                key={reason}
                className="bg-surface-sunken text-content-secondary max-w-full truncate rounded-full px-2.5 py-1 text-xs"
                dir="auto"
              >
                {reason} · {n(count)}
              </span>
            ))}
          </div>
        </CardContent>
      </Card>
    </section>
  );
}
