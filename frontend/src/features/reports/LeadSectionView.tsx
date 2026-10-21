import { localeTag } from '@/shared/lib/format';
import { AlertTriangle, Lightbulb, Star } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { MISSING } from '@/shared/metrics';
import { ChartFrame } from '@/shared/ui/chart';
import { DonutChart, FunnelBars, RankingBars } from '@/shared/ui/viz';
import {
  leadAdvice,
  leadCampaigns,
  leadFunnel,
  leadInsights,
  type LeadInsightKind,
} from './leadHighlights';
import type { LeadAggregates, RenderedSection } from './types';

const SLICE_COLOURS = [
  'var(--ds-chart-1)',
  'var(--ds-chart-2)',
  'var(--ds-chart-3)',
  'var(--ds-chart-4)',
  'var(--ds-chart-5)',
  'var(--ds-chart-6)',
];

const STAGE_ORDER = ['won', 'qualified', 'contacted', 'new', 'disqualified', 'lost'] as const;

/** A duration in the reader's own language and numerals: "42 min", "٤٢ د". */
function duration(minutes: number, locale: string): string {
  return minutes >= 120
    ? new Intl.NumberFormat(localeTag(locale), {
        style: 'unit',
        unit: 'hour',
        unitDisplay: 'narrow',
        maximumFractionDigits: 1,
      }).format(minutes / 60)
    : new Intl.NumberFormat(localeTag(locale), {
        style: 'unit',
        unit: 'minute',
        unitDisplay: 'narrow',
      }).format(minutes);
}

/**
 * How a client's recorded leads turned out — aggregates and nothing else.
 *
 * Every figure is one the server sent; the cards on top only *read* two of them (see `leadHighlights.ts`). A rate
 * with nothing to divide by is written as "not available", never as 0%. The section holds no lead record, so there
 * is nothing here that could show a name, a number, a note or an owner.
 *
 * Qualified is where a lead stands now. It sits beside the funnel, not in it: a lead since won passed through
 * qualified uncounted, and a funnel that narrowed and then widened would be read as leads being won unqualified.
 */
export function LeadSectionView({ section }: { section: RenderedSection }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const lead = section.lead;

  if (lead == null) {
    return null;
  }

  const n = (value: number): string => new Intl.NumberFormat(localeTag(locale)).format(value);
  const pct = (value: number | null): string =>
    value === null
      ? MISSING
      : new Intl.NumberFormat(localeTag(locale), {
          style: 'percent',
          maximumFractionDigits: 1,
        }).format(value / 100);
  const median = lead.response.median_seconds;

  return (
    <ChartFrame
      title={section.title}
      caveats={section.caveats}
      isEmpty={section.is_empty}
      emptyTitle={t('reports.section.emptyTitle')}
      emptyBody={t('reports.section.emptyBody')}
      table={{
        columns: [t('reports.table.metric'), t('reports.table.value')],
        rows: [
          { key: 'total', label: t('reports.lead.leads'), cells: [n(lead.total)] },
          { key: 'reached', label: t('reports.lead.reached'), cells: [n(lead.reached)] },
          { key: 'qualified', label: t('reports.lead.qualified'), cells: [n(lead.qualified)] },
          { key: 'won', label: t('reports.lead.won'), cells: [n(lead.won)] },
          {
            key: 'rate',
            label: t('reports.lead.qualifiedRate'),
            cells: [pct(lead.qualification_rate)],
          },
        ],
      }}
    >
      <div className="flex flex-col gap-5">
        <p className="text-content-secondary text-xs">{t('reports.lead.fromRecorded')}</p>

        <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5">
          <Tile label={t('reports.lead.leads')} value={n(lead.total)} />
          <Tile label={t('reports.lead.qualified')} value={n(lead.qualified)} />
          <Tile label={t('reports.lead.qualifiedRate')} value={pct(lead.qualification_rate)} />
          <Tile label={t('reports.lead.won')} value={n(lead.won)} />
          <Tile
            label={t('reports.lead.median')}
            value={
              median === null ? MISSING : duration(Math.max(1, Math.round(median / 60)), locale)
            }
          />
        </div>

        <Cards lead={lead} duration={(minutes) => duration(minutes, locale)} />

        <div className="grid gap-5 lg:grid-cols-2">
          <Funnel lead={lead} n={n} />
          <Stages lead={lead} n={n} />
        </div>

        <div className="grid gap-5 lg:grid-cols-2">
          <Sources lead={lead} n={n} />
          <Response lead={lead} n={n} />
        </div>

        <Campaigns lead={lead} n={n} />
      </div>
    </ChartFrame>
  );
}

function Tile({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-surface-sunken flex min-w-0 flex-col gap-1 rounded-2xl p-4">
      <span className="text-content-tertiary ds-overline">{label}</span>
      <span
        className={
          value === MISSING
            ? 'text-content-tertiary text-xl'
            : 'ds-figure text-content text-2xl [overflow-wrap:anywhere]'
        }
      >
        {value}
      </span>
    </div>
  );
}

const ICON: Record<LeadInsightKind, typeof Star> = { best: Star, attention: AlertTriangle };

function Cards({
  lead,
  duration: format,
}: {
  lead: LeadAggregates;
  duration: (minutes: number) => string;
}) {
  const { t } = useTranslation();
  const found = leadInsights(lead);
  const advice = leadAdvice(lead);

  if (found.length === 0 && advice.length === 0) {
    return null;
  }

  const fill = (values: Record<string, string | number>): Record<string, string | number> =>
    typeof values['minutes'] === 'number'
      ? { ...values, duration: format(values['minutes']) }
      : values;

  return (
    <div className="flex flex-col gap-3">
      {found.length === 0 ? null : (
        <ul
          aria-label={t('reports.lead.insightsLabel')}
          className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
          {found.map((insight) => {
            const Icon = ICON[insight.kind];

            return (
              <li
                key={insight.key}
                className="border-line-subtle bg-surface flex min-w-0 items-start gap-3 rounded-2xl border p-4"
              >
                <Icon aria-hidden className="text-content-brand mt-0.5 size-4 shrink-0" />
                <p className="text-content min-w-0 text-sm leading-snug">
                  {t(`reports.lead.insight.${insight.text}`, fill(insight.values))}
                </p>
              </li>
            );
          })}
        </ul>
      )}
      {advice.length === 0 ? null : (
        <ul aria-label={t('reports.lead.advice')} className="grid gap-3 md:grid-cols-2">
          {advice.map((item) => (
            <li
              key={item.key}
              className="border-line-subtle bg-accent-subtle/40 flex min-w-0 items-start gap-3 rounded-2xl border p-4"
            >
              <Lightbulb aria-hidden className="text-content-brand mt-0.5 size-4 shrink-0" />
              <div className="min-w-0">
                <p className="text-content text-sm font-medium">
                  {t(`reports.lead.adviceText.${item.text}`, fill(item.values))}
                </p>
                <p className="text-content-secondary text-xs">
                  {t(`reports.lead.adviceText.${item.evidence}`, fill(item.values))}
                </p>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function Funnel({ lead, n }: { lead: LeadAggregates; n: (value: number) => string }) {
  const { t } = useTranslation();
  const stages = leadFunnel(lead);

  if (stages === null) {
    return null;
  }

  return (
    <section className="flex min-w-0 flex-col gap-2">
      <h4 className="text-content text-sm font-medium">{t('reports.lead.funnel')}</h4>
      <FunnelBars
        label={t('reports.lead.funnel')}
        notTracked={t('analytics.funnel.notTracked')}
        leakFrom={1}
        leakLabel={t('reports.highlights.leak')}
        stages={stages.map((stage) => ({
          key: stage.key,
          label: t(`reports.lead.funnelStage.${stage.key}`),
          value: stage.value,
          display: n(stage.value),
        }))}
      />
      <p className="text-content-tertiary text-xs">{t('reports.lead.funnelNote')}</p>
    </section>
  );
}

function Stages({ lead, n }: { lead: LeadAggregates; n: (value: number) => string }) {
  const { t } = useTranslation();
  const segments = STAGE_ORDER.flatMap((stage, index) =>
    lead.stages[stage] > 0
      ? [
          {
            key: stage,
            label: t(`reports.lead.stage.${stage}`),
            value: lead.stages[stage],
            colour: SLICE_COLOURS[index] ?? 'var(--ds-chart-6)',
          },
        ]
      : [],
  );

  if (segments.length === 0) {
    return null;
  }

  return (
    <section className="flex min-w-0 flex-col gap-2">
      <h4 className="text-content text-sm font-medium">{t('reports.lead.stages')}</h4>
      <DonutChart
        label={t('reports.lead.stages')}
        segments={segments}
        centre={{ value: n(lead.total), caption: t('reports.lead.leads') }}
        format={n}
      />
    </section>
  );
}

function Sources({ lead, n }: { lead: LeadAggregates; n: (value: number) => string }) {
  const { t } = useTranslation();
  const rows = lead.sources.filter((row) => row.total > 0);

  if (rows.length === 0) {
    return null;
  }

  // Past six slices a ring is a colour wheel: the tail folds into the ones already shown as a count, not a slice.
  const shown = rows.slice(0, 6);

  return (
    <section className="flex min-w-0 flex-col gap-2">
      <h4 className="text-content text-sm font-medium">{t('reports.lead.sources')}</h4>
      <DonutChart
        label={t('reports.lead.sources')}
        segments={shown.map((row, index) => ({
          key: row.kind,
          label: row.label,
          value: row.total,
          colour: SLICE_COLOURS[index] ?? 'var(--ds-chart-6)',
        }))}
        centre={{ value: n(lead.total), caption: t('reports.lead.leads') }}
        format={n}
      />
    </section>
  );
}

function Response({ lead, n }: { lead: LeadAggregates; n: (value: number) => string }) {
  const { t } = useTranslation();
  const { measured, unanswered, buckets } = lead.response;

  if (measured === 0) {
    return null;
  }

  return (
    <section className="flex min-w-0 flex-col gap-2">
      <h4 className="text-content text-sm font-medium">{t('reports.lead.response')}</h4>
      <RankingBars
        label={t('reports.lead.response')}
        rows={buckets.map((count, index) => ({
          key: `b${index}`,
          label: t(`reports.lead.bucket.b${index}`),
          value: count,
          display: n(count),
        }))}
      />
      <p className="text-content-tertiary text-xs">
        {t('reports.lead.responseNote', { measured: n(measured), unanswered: n(unanswered) })}
      </p>
    </section>
  );
}

function Campaigns({ lead, n }: { lead: LeadAggregates; n: (value: number) => string }) {
  const { t } = useTranslation();
  const { top, attention } = leadCampaigns(lead);
  const unattributed = lead.campaigns.find((row) => row.is_unattributed)?.total ?? 0;

  if (top.length === 0 && unattributed === 0) {
    return null;
  }

  return (
    <section className="flex min-w-0 flex-col gap-3">
      <h4 className="text-content text-sm font-medium">{t('reports.lead.campaigns')}</h4>
      <div className="grid gap-4 lg:grid-cols-2">
        {top.length === 0 ? null : (
          <div className="flex min-w-0 flex-col gap-2">
            <h5 className="text-content-secondary text-xs">{t('reports.lead.top')}</h5>
            <ul className="flex flex-col gap-2">
              {top.map((card) => (
                <li
                  key={card.key}
                  className="border-line-subtle bg-surface min-w-0 rounded-2xl border p-3"
                >
                  <p className="text-content truncate text-sm font-medium">{card.label}</p>
                  <p className="text-content-secondary text-xs">
                    {t('reports.lead.campaignLine', {
                      qualified: n(card.qualified),
                      total: n(card.total),
                    })}
                  </p>
                </li>
              ))}
            </ul>
          </div>
        )}
        {attention.length === 0 ? null : (
          <div className="flex min-w-0 flex-col gap-2">
            <h5 className="text-content-secondary text-xs">{t('reports.lead.needs')}</h5>
            <ul className="flex flex-col gap-2">
              {attention.map((card) => (
                <li
                  key={card.key}
                  className="border-line-subtle bg-surface min-w-0 rounded-2xl border p-3"
                >
                  <p className="text-content truncate text-sm font-medium">{card.label}</p>
                  <p className="text-content-secondary text-xs">
                    {t('reports.lead.campaignNone', { total: n(card.total) })}
                  </p>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
      {unattributed === 0 ? null : (
        <p className="text-content-tertiary text-xs">
          {t('reports.lead.unattributed', { count: n(unattributed) })}
        </p>
      )}
    </section>
  );
}
