import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import {
  HeroTrend,
  analyticsAsReport,
  useAnalytics,
  useMetricCatalogue,
} from '@/features/analytics';
import { CreativeMediaTile, evidenceChips, useCreatives } from '@/features/creatives';
import { ProviderLogo } from '@/features/integrations';
import { ReportHighlights } from '@/features/reports';
import { formatMetric, MISSING } from '@/shared/metrics';
import type { MetricValue } from '@/shared/metrics';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Breadcrumbs } from '@/shared/ui/breadcrumbs';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { KpiTile } from '@/shared/ui/kpi-tile';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { PRESET } from './filters';
import { adsInGroupLink } from './links';

/** What the ad's own analytics asks for: the figures the trend plots and the funnel reads. */
const ANALYTICS_METRICS = [
  'spend',
  'revenue',
  'roas',
  'conversions',
  'cpa',
  'impressions',
  'clicks',
  'ctr',
  'cpc',
  'cpm',
  'frequency',
];

/**
 * One ad: its picture first, then how it is doing and whether it is wearing out.
 *
 * The figures, the picture, the fatigue verdict and its evidence are the creative layer's, at the grain of an ad — the
 * same ones the Ads list shows for it. The trend is the shared analytics layer narrowed to this ad's own rows, so a
 * click-through, cost or frequency line here is the one a client's report would draw. Nothing about an audience or a
 * placement appears: this product does not store either per ad, and a section would have to invent it.
 */
export function AdDetailPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { adId = '' } = useParams();
  const period = useUiStore((state) => state.period);

  const one = useCreatives(
    {
      preset: PRESET[period.days] ?? '28d',
      grain: 'ad',
      ad_ids: [adId],
      limit: 1,
    },
    adId !== '',
  );
  const row = one.data?.rows[0] ?? null;

  const catalogue = useMetricCatalogue();
  const [heroMetric, setHeroMetric] = useState('spend');
  const analytics = useAnalytics(
    {
      preset: ({ 7: '7d', 30: '30d', 90: '90d' } as const)[period.days],
      compare: period.compare,
      metrics: ANALYTICS_METRICS,
      order_by: 'spend',
      limit: 10,
      ad_ids: [adId],
    },
    adId !== '',
  );
  const asReport = useMemo(
    () =>
      analytics.data === undefined || analytics.data.is_empty
        ? null
        : analyticsAsReport(analytics.data, undefined, undefined),
    [analytics.data],
  );
  const metricLabels = useMemo(
    () => new Map([...catalogue.definitions].map(([key, def]) => [key, def.label])),
    [catalogue.definitions],
  );

  const show = (value: MetricValue | undefined): string =>
    value === undefined ? MISSING : formatMetric(value, locale, true);
  const parents = row?.parents ?? null;
  const trail = [
    { label: t('adGroups.crumbCampaigns'), to: '/campaigns' },
    ...(parents?.campaign == null
      ? []
      : [
          {
            label: parents.campaign.name ?? t('ads.detail.campaign'),
            to: `/campaigns/${parents.campaign.id}`,
          },
        ]),
    ...(parents?.ad_group == null
      ? []
      : [
          {
            label: parents.ad_group.name ?? t('ads.detail.adGroup'),
            to: `/ad-groups/${parents.ad_group.id}`,
          },
        ]),
    ...(row === null ? [] : [{ label: row.label }]),
  ];
  const chips = row === null ? [] : evidenceChips(row, locale, t);

  return (
    <div className="ds-page">
      <Breadcrumbs items={trail} label={t('adGroups.breadcrumb')} />
      <PageHeader
        title={row?.label ?? t('ads.detail.title')}
        description={row === null ? undefined : `${row.platform_label} · ${row.format_label}`}
        actions={
          <Link to="/ads" className={buttonVariants({ variant: 'ghost' })}>
            {t('ads.detail.back')}
          </Link>
        }
      />

      {one.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : row === null ? (
        <Alert tone="warning">{t('ads.detail.notFound')}</Alert>
      ) : (
        <div className="flex min-w-0 flex-col gap-4">
          <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <div className="border-line-subtle bg-surface overflow-hidden rounded-2xl border">
              <CreativeMediaTile media={row.media} format={row.format} label={row.label} />
            </div>
            <div className="flex min-w-0 flex-col gap-3">
              <div className="flex flex-wrap items-center gap-3">
                <ProviderLogo provider={row.platform as never} className="size-7" />
                {row.ad_status == null ? null : (
                  <Badge tone="neutral">
                    {t(`campaignControl.statusName.${row.ad_status}`, {
                      defaultValue: row.ad_status,
                    })}
                  </Badge>
                )}
                {row.verdict === null ? null : (
                  <Badge
                    tone={
                      row.verdict === 'fatiguing' || row.verdict === 'weak' ? 'warning' : 'neutral'
                    }
                  >
                    {t(`creatives.verdict.${row.verdict}`)}
                  </Badge>
                )}
              </div>
              {chips.length === 0 ? null : (
                <ul aria-label={t('ads.detail.evidence')} className="flex flex-wrap gap-1.5">
                  {chips.map((chip) => (
                    <li
                      key={chip}
                      className="bg-surface-sunken text-content-secondary rounded-full px-2 py-0.5 text-xs"
                    >
                      <bdi dir="ltr">{chip}</bdi>
                    </li>
                  ))}
                </ul>
              )}
              <section
                aria-label={t('ads.detail.kpis')}
                className="grid grid-cols-2 gap-3 xl:grid-cols-3"
              >
                <KpiTile
                  highlight
                  label={t('campaignControl.col.spend')}
                  value={show(row.metrics.values['spend'])}
                />
                <KpiTile
                  label={t('campaignControl.col.result')}
                  value={show(row.metrics.values['conversions'])}
                />
                <KpiTile label="CPA" value={show(row.metrics.values['cpa'])} />
                <KpiTile
                  label={t('campaignControl.col.ctr')}
                  value={show(row.metrics.values['ctr'])}
                />
                {row.metrics.values['roas']?.value == null ? null : (
                  <KpiTile label="ROAS" value={show(row.metrics.values['roas'])} />
                )}
                <KpiTile
                  label={t('creatives.frequency')}
                  value={show(row.metrics.values['frequency'])}
                />
              </section>
            </div>
          </div>

          {analytics.data === undefined || analytics.data.is_empty ? null : (
            <Card>
              <CardHeader>
                <CardTitle>{t('campaignDetail.performance')}</CardTitle>
              </CardHeader>
              <CardContent>
                <HeroTrend
                  data={analytics.data}
                  metric={heroMetric}
                  onMetric={setHeroMetric}
                  label={t('ads.detail.trendLabel', { name: row.label })}
                  labels={metricLabels}
                />
              </CardContent>
            </Card>
          )}

          {/* The funnel and what stands out, from the report system's own reader over this ad's figures. */}
          {asReport === null ? null : <ReportHighlights report={asReport} />}

          <div className="flex flex-wrap gap-2">
            {parents?.ad_group == null ? null : (
              <Link
                to={adsInGroupLink({
                  id: parents.ad_group.id,
                  name: parents.ad_group.name ?? '',
                })}
                className={buttonVariants({ variant: 'secondary' })}
              >
                {t('ads.detail.siblings')}
              </Link>
            )}
            <Link to="/creatives" className={buttonVariants({ variant: 'secondary' })}>
              {t('ads.detail.creativeIntelligence')}
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}
