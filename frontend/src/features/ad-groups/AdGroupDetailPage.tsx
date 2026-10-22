import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import {
  HeroTrend,
  analyticsAsReport,
  useAnalytics,
  useMetricCatalogue,
} from '@/features/analytics';
import { ProviderLogo } from '@/features/integrations';
import {
  BudgetBar,
  HEALTH_TO_STATE,
  money,
  objectiveName,
  resultName,
  resultText,
  statusName,
  useCampaignHierarchy,
} from '@/features/campaign-control';
import { ReportHighlights } from '@/features/reports';
import { formatPercent } from '@/shared/lib/format';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Breadcrumbs } from '@/shared/ui/breadcrumbs';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { HealthPill } from '@/shared/ui/health';
import { KpiTile } from '@/shared/ui/kpi-tile';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { adGroupsLinkFor } from './links';
import { useAdGroup } from './useAdGroups';

/** What the ad group's own analytics asks for: the figures the hero chart plots and the funnel reads. */
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
];

/**
 * One ad group.
 *
 * Its figures are the list's own row, asked for by id, so this page and the row that linked to it cannot disagree. The
 * trend and the funnel are the shared analytics layer narrowed to the ad group, which reads the ad group's **own**
 * metric rows. What the platform did not carry is not drawn: an audience appears only where the sync stored one, and
 * there is no placement section because nothing here stores placements.
 */
export function AdGroupDetailPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { adGroupId = '' } = useParams();
  const period = useUiStore((state) => state.period);

  const one = useAdGroup(adGroupId, period.days);
  const row = one.data?.data[0] ?? null;

  const catalogue = useMetricCatalogue();
  const [heroMetric, setHeroMetric] = useState('spend');
  const analytics = useAnalytics(
    {
      preset: ({ 7: '7d', 30: '30d', 90: '90d' } as const)[period.days],
      compare: period.compare,
      metrics: ANALYTICS_METRICS,
      order_by: 'spend',
      limit: 10,
      ad_group_ids: [adGroupId],
    },
    adGroupId !== '',
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

  /* The ads inside this group, from the parent campaign's hierarchy — the same read the campaign page uses. */
  const hierarchy = useCampaignHierarchy(row?.campaign.id ?? '', row !== null);
  const group = hierarchy.data?.groups.find((candidate) => candidate.id === adGroupId) ?? null;

  const trail = [
    { label: t('adGroups.crumbCampaigns'), to: '/campaigns' },
    ...(row === null
      ? []
      : [{ label: row.campaign.name, to: `/campaigns/${row.campaign.id}` }, { label: row.name }]),
  ];

  return (
    <div className="ds-page">
      <Breadcrumbs items={trail} label={t('adGroups.breadcrumb')} />
      <PageHeader
        title={row?.name ?? t('adGroups.detail.title')}
        description={
          row === null ? undefined : [row.client_name, row.project_name].filter(Boolean).join(' · ')
        }
        actions={
          <Link to="/ad-groups" className={buttonVariants({ variant: 'ghost' })}>
            {t('adGroups.detail.back')}
          </Link>
        }
      />

      {one.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : row === null ? (
        <Alert tone="warning">{t('adGroups.detail.notFound')}</Alert>
      ) : (
        <div className="flex min-w-0 flex-col gap-4">
          <div className="flex flex-wrap items-center gap-3">
            <ProviderLogo provider={row.provider as never} className="size-7" />
            <Badge tone="neutral">{statusName(t, row)}</Badge>
            {/* The campaign's own verdict, named as the campaign's: an ad group has none of its own. */}
            {row.campaign_health === null ? null : (
              <span className="flex items-center gap-2">
                <HealthPill state={HEALTH_TO_STATE[row.campaign_health.health]} />
                <Link
                  to={`/campaigns/${row.campaign.id}`}
                  className="text-content-tertiary text-xs hover:underline"
                >
                  {t('adGroups.detail.campaignHealth')}
                </Link>
              </span>
            )}
            <span className="text-content-tertiary text-xs">
              {objectiveName(t, row)} · <span dir="auto">{row.campaign.name}</span>
            </span>
          </div>

          <section
            aria-label={t('adGroups.detail.kpis')}
            className="grid grid-cols-2 gap-4 lg:grid-cols-3 2xl:grid-cols-6"
          >
            <KpiTile
              highlight
              label={t('campaignControl.col.spend')}
              value={money(row.spend_minor, row.currency, locale)}
            />
            <KpiTile label={resultName(t, row)} value={resultText(row, locale)} />
            <KpiTile
              label={t('campaignControl.col.costPerResult')}
              value={money(row.cost_per_result_minor, row.currency, locale)}
            />
            {row.revenue_minor === null ? null : (
              <KpiTile
                label={t('campaignDetail.revenue')}
                value={money(row.revenue_minor, row.currency, locale)}
              />
            )}
            {row.roas === null ? null : <KpiTile label="ROAS" value={`${row.roas.toFixed(2)}×`} />}
            {row.ctr === null ? null : (
              <KpiTile
                label={t('campaignControl.col.ctr')}
                value={formatPercent(row.ctr, locale, 2)}
              />
            )}
          </section>

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
                  label={t('adGroups.detail.trendLabel', { name: row.name })}
                  labels={metricLabels}
                />
              </CardContent>
            </Card>
          )}

          {/* The funnel and what stands out, from the report system's own reader over this ad group's figures. */}
          {asReport === null ? null : <ReportHighlights report={asReport} />}

          {row.budget_minor === null ? null : (
            <Card>
              <CardHeader>
                <CardTitle>{t('campaignDetail.budget')}</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-2">
                <span className="ds-numeric text-sm">
                  {money(row.budget_minor, row.currency, locale)}
                </span>
                <BudgetBar row={row} />
              </CardContent>
            </Card>
          )}

          {group?.audience == null ? null : (
            <Card>
              <CardHeader>
                <CardTitle>{t('adGroups.detail.audience')}</CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-sm" dir="auto">
                  {group.audience}
                </p>
              </CardContent>
            </Card>
          )}

          <Card>
            <CardHeader>
              <CardTitle>{t('adGroups.detail.ads')}</CardTitle>
            </CardHeader>
            <CardContent>
              {group === null ? (
                <p className="text-content-tertiary text-sm">
                  {hierarchy.isPending ? t('adGroups.loading') : t('adGroups.detail.noAds')}
                </p>
              ) : group.ads.length === 0 ? (
                <p className="text-content-tertiary text-sm">{t('adGroups.detail.noAds')}</p>
              ) : (
                <ul aria-label={t('adGroups.detail.ads')} className="flex flex-col gap-2">
                  {group.ads.map((ad) => (
                    <li
                      key={ad.id}
                      className="border-line-subtle flex flex-wrap items-center justify-between gap-2 rounded-xl border p-3 text-sm"
                    >
                      <span className="flex min-w-0 flex-col">
                        <Link
                          to={`/ads/${ad.id}`}
                          className="truncate font-medium hover:underline"
                          dir="auto"
                        >
                          {ad.name}
                        </Link>
                        <span className="text-content-tertiary text-xs">
                          {ad.creative?.format ?? t('adGroups.detail.noCreative')}
                        </span>
                      </span>
                      <span className="flex items-center gap-3">
                        <Badge tone="neutral">
                          {t(`campaignControl.statusName.${ad.status}`, {
                            defaultValue: ad.status,
                          })}
                        </Badge>
                        <span className="ds-numeric">
                          {money(ad.spend_minor, row.currency, locale)}
                        </span>
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CardContent>
          </Card>

          <div className="flex flex-wrap gap-2">
            <Link
              to={adGroupsLinkFor(row.campaign)}
              className={buttonVariants({ variant: 'secondary' })}
            >
              {t('adGroups.detail.siblings')}
            </Link>
            {/* The ads of this one ad group, as the Ads screen shows them — pictures first. */}
            <Link
              to={`/ads?group=${encodeURIComponent(`${row.id}~${row.name}`)}`}
              className={buttonVariants({ variant: 'secondary' })}
            >
              {t('adGroups.detail.openAds')}
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}
