import { AlertTriangle, ArrowRight, Lightbulb, Star, TrendingDown, TrendingUp } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { formatChange, formatMetric } from '@/shared/metrics';
import type { MetricValue } from '@/shared/metrics';
import { cn } from '@/shared/lib/cn';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { FunnelBars } from '@/shared/ui/viz';
import {
  campaignCards,
  funnelStages,
  insights,
  recommendations,
  type CampaignCard,
  type Insight,
  type InsightKind,
  type Recommendation,
} from './highlights';
import type { RenderedReport } from './types';

const KIND: Record<InsightKind, { icon: typeof TrendingUp; tone: string }> = {
  improved: { icon: TrendingUp, tone: 'text-success' },
  declined: { icon: TrendingDown, tone: 'text-content-secondary' },
  attention: { icon: AlertTriangle, tone: 'text-warning' },
  best: { icon: Star, tone: 'text-content-brand' },
};

/**
 * What the report says, before the sections that prove it: a few changes, the campaigns that carried it and the
 * ones that lagged, and — only where the figures support one — a pointer. Every card shows the figures that made it,
 * and none of it is computed here: see `highlights.ts`.
 *
 * Absent when there is nothing to say. A panel reading "no insights" would be furniture, and silence is honest.
 */
export function ReportHighlights({
  report,
  onViewDetails,
}: {
  report: RenderedReport;
  /** Opens the working: the tables and breakdowns behind these cards. Absent when they are already showing. */
  onViewDetails?: (() => void) | undefined;
}) {
  const { t, i18n } = useTranslation();
  const found = insights(report);
  const { top, attention } = campaignCards(report);
  const advice = recommendations(report);
  const funnel = funnelStages(report);

  if (found.length === 0 && top.length === 0 && advice.length === 0 && funnel === null) {
    return null;
  }

  return (
    <div className="flex flex-col gap-6">
      {found.length === 0 ? null : (
        <ul
          aria-label={t('reports.highlights.insights')}
          className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        >
          {found.map((insight) => (
            <InsightCard key={insight.key} insight={insight} />
          ))}
        </ul>
      )}

      {funnel === null ? null : (
        <Card>
          <CardHeader>
            <CardTitle>{t('reports.highlights.funnel')}</CardTitle>
          </CardHeader>
          <CardContent>
            <FunnelBars
              label={t('reports.highlights.funnel')}
              notTracked={t('analytics.funnel.notTracked')}
              /*
               * The search for the biggest leak starts after the first step: impressions to clicks is click-through
               * rate, always the steepest drop and judged on its own. What is left is where an audience that did click
               * was lost.
               */
              leakFrom={2}
              leakLabel={t('reports.highlights.leak')}
              stages={funnel.map((stage) => {
                const change = formatChange(stage.value, i18n.language);

                return {
                  key: stage.key,
                  label: stage.value.label,
                  value: stage.value.value,
                  display: formatMetric(stage.value, i18n.language, true),
                  /* The server's own change against the earlier period; absent where it withheld one. */
                  ...(change === null
                    ? {}
                    : { change: { text: change, good: stage.value.change_is_good } }),
                };
              })}
            />
          </CardContent>
        </Card>
      )}

      {top.length === 0 ? null : (
        <section aria-label={t('reports.highlights.campaigns')} className="flex flex-col gap-3">
          <div className="grid gap-4 lg:grid-cols-2">
            <CampaignColumn title={t('reports.highlights.top')} cards={top} />
            {attention.length === 0 ? null : (
              <CampaignColumn title={t('reports.highlights.needs')} cards={attention} warn />
            )}
          </div>
          {onViewDetails === undefined ? null : (
            <div>
              <Button variant="ghost" size="sm" onClick={onViewDetails}>
                {t('reports.highlights.details')}
                <ArrowRight aria-hidden className="ms-1 size-3.5 rtl:rotate-180" />
              </Button>
            </div>
          )}
        </section>
      )}

      {advice.length === 0 ? null : (
        <ul
          aria-label={t('reports.highlights.recommendations')}
          className="grid gap-3 md:grid-cols-2"
        >
          {advice.map((item) => (
            <RecommendationCard key={item.key} item={item} />
          ))}
        </ul>
      )}
    </div>
  );
}

function InsightCard({ insight }: { insight: Insight }) {
  const { t, i18n } = useTranslation();
  const { icon: Icon, tone } = KIND[insight.kind];
  const change = formatChange(insight.value, i18n.language);

  return (
    <li className="bg-surface flex flex-col gap-1.5 rounded-2xl p-4 shadow-xs">
      <span className={cn('flex items-center gap-1.5 text-xs font-semibold', tone)}>
        <Icon aria-hidden className="size-4" />
        {t(`reports.highlights.kind.${insight.kind}`)}
      </span>
      <span className="text-content-secondary truncate text-xs" dir="auto">
        {insight.subject ?? insight.value.label}
      </span>
      <span className="flex items-baseline gap-2">
        <span className="ds-figure text-content text-xl">
          {formatMetric(insight.value, i18n.language)}
        </span>
        {insight.kind === 'best' || change === null ? null : (
          <span className={cn('ds-numeric text-xs font-semibold', tone)}>
            <bdi dir="ltr">{change}</bdi>
          </span>
        )}
      </span>
    </li>
  );
}

function CampaignColumn({
  title,
  cards,
  warn = false,
}: {
  title: string;
  cards: CampaignCard[];
  warn?: boolean;
}) {
  return (
    <div className="flex flex-col gap-2">
      <h3 className={cn('text-sm font-semibold', warn ? 'text-warning' : 'text-content')}>
        {title}
      </h3>
      <ul className="flex flex-col gap-2">
        {cards.map((card) => (
          <CampaignRow key={card.key} card={card} warn={warn} />
        ))}
      </ul>
    </div>
  );
}

const SHOWN = ['spend', 'conversions', 'revenue'] as const;

function CampaignRow({ card, warn }: { card: CampaignCard; warn: boolean }) {
  const { t, i18n } = useTranslation();
  const figures = SHOWN.map((metric) => card.values[metric]).filter(
    (value): value is MetricValue => value !== undefined && value.value !== null,
  );

  return (
    <li
      className={cn(
        'bg-surface flex flex-col gap-2 rounded-2xl p-4 shadow-xs',
        warn && 'ring-1 ring-[var(--ds-warning-500)]/40',
      )}
    >
      <span className="text-content truncate text-sm font-medium" dir="auto">
        {card.label}
      </span>
      <dl className="flex flex-wrap gap-x-5 gap-y-1">
        {figures.map((value) => (
          <div key={value.metric}>
            <dt className="text-content-tertiary ds-overline">{value.label}</dt>
            <dd className="ds-numeric text-content text-sm font-semibold">
              {formatMetric(value, i18n.language, true)}
            </dd>
          </div>
        ))}
        {card.efficiency === null ? null : (
          <div>
            <dt className="text-content-tertiary ds-overline">{card.efficiency.label}</dt>
            <dd className="ds-numeric text-content text-sm font-semibold">
              {formatMetric(card.efficiency, i18n.language)}
              {warn && card.against !== null ? (
                <span className="text-content-tertiary ms-1.5 text-xs font-normal">
                  {t('reports.highlights.vsReport', {
                    value: formatMetric(card.against, i18n.language),
                  })}
                </span>
              ) : null}
            </dd>
          </div>
        )}
      </dl>
    </li>
  );
}

function RecommendationCard({ item }: { item: Recommendation }) {
  const { t, i18n } = useTranslation();
  const current = formatMetric(item.current, i18n.language);
  const against = item.against === null ? null : formatMetric(item.against, i18n.language);
  const change = formatChange(item.current, i18n.language);

  return (
    <li className="bg-surface flex flex-col gap-2 rounded-2xl p-4 shadow-xs">
      <span className="text-content flex items-center gap-2 text-sm font-semibold">
        <Lightbulb aria-hidden className="size-4 text-[var(--ds-accent)]" />
        {t(`reports.highlights.rec.${item.key}`, { subject: item.subject ?? '' })}
      </span>
      {/* The evidence, always: a pointer with no figures beside it is an opinion. */}
      <span className="text-content-secondary ds-numeric text-xs">
        <span className="ds-overline text-content-tertiary me-1.5">
          {t('reports.highlights.evidence')}
        </span>
        {item.current.label} <bdi dir="ltr">{current}</bdi>
        {against === null ? (
          change === null ? null : (
            <>
              {' · '}
              <bdi dir="ltr">{change}</bdi>
            </>
          )
        ) : (
          <> {t('reports.highlights.vsOverall', { value: against })}</>
        )}
      </span>
    </li>
  );
}
