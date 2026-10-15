import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { AttentionPanel } from '@/features/attention';
import { Alert } from '@/shared/ui/alert';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';
import {
  ClientLink,
  Count,
  HealthSection,
  KpiGrid,
  MoneyLines,
  PlatformMix,
  RoasLines,
} from './PortfolioParts';
import { TrackingRollup } from './TrackingRollup';
import { usePortfolio } from './usePortfolio';

/**
 * How the agency is doing, in one page.
 *
 * Everything on it is the server's assembly of reads that already exist — campaign verdicts, budget risks,
 * tracking freshness — so no figure here can disagree with the surface it links to. Drill-down is by link:
 * agency → client → project → campaign, each one a page that already exists.
 */
export function PortfolioPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { data, isPending, isError } = usePortfolio();

  return (
    <div className="ds-page">
      <PageHeader
        title={t('portfolio.title')}
        description={t('portfolio.subtitle')}
        actions={
          <Link to="/campaigns" className={buttonVariants({ variant: 'secondary' })}>
            {t('portfolio.openCampaigns')}
          </Link>
        }
      />

      {isError ? <Alert tone="danger">{t('portfolio.loadFailed')}</Alert> : null}

      {isPending || data === undefined ? (
        <Skeleton className="h-64 w-full" />
      ) : (
        <>
          {data.is_truncated ? (
            <Alert tone="warning">
              {t('portfolio.truncated', { assessed: data.assessed, total: data.total_campaigns })}
            </Alert>
          ) : null}

          <KpiGrid data={data.kpis} locale={locale} />

          <div className="grid gap-4 lg:grid-cols-3">
            <Card className="lg:col-span-2">
              <CardHeader>
                <CardTitle>{t('portfolio.health')}</CardTitle>
              </CardHeader>
              <CardContent>
                <HealthSection health={data.health} />
              </CardContent>
            </Card>

            <div className="flex flex-col gap-4">
              {data.budget_health === null ? null : (
                <Card>
                  <CardContent className="flex items-center justify-between pt-5">
                    <div>
                      <p className="ds-overline text-content-tertiary">
                        {t('portfolio.budgetHealth')}
                      </p>
                      <p className="ds-figure text-xl">
                        {data.budget_health.at_risk}
                        <span className="text-content-tertiary text-sm">
                          {' '}
                          / {data.budget_health.approved}
                        </span>
                      </p>
                    </div>
                    <Link
                      to="/budget-control"
                      className={buttonVariants({ variant: 'ghost', size: 'sm' })}
                    >
                      {t('portfolio.open')}
                    </Link>
                  </CardContent>
                </Card>
              )}
              {data.tracking_health === null ? null : (
                <Card>
                  <CardContent className="flex items-center justify-between pt-5">
                    <div>
                      <p className="ds-overline text-content-tertiary">
                        {t('portfolio.trackingHealth')}
                      </p>
                      <p className="ds-figure text-xl">
                        {data.tracking_health.needing_attention}
                        <span className="text-content-tertiary text-sm">
                          {' '}
                          / {data.tracking_health.sources}
                        </span>
                      </p>
                    </div>
                    <Link
                      to="/tracking"
                      className={buttonVariants({ variant: 'ghost', size: 'sm' })}
                    >
                      {t('portfolio.open')}
                    </Link>
                  </CardContent>
                </Card>
              )}
            </div>
          </div>

          <Card>
            <CardHeader>
              <CardTitle>{t('portfolio.clients')}</CardTitle>
            </CardHeader>
            <CardContent>
              <DataTable label={t('portfolio.clients')} minWidth="56rem">
                <TableHead>
                  <TableRow>
                    <TableHeader>{t('portfolio.col.client')}</TableHeader>
                    <TableHeader align="end">{t('portfolio.kpi.spend')}</TableHeader>
                    <TableHeader align="end">{t('portfolio.kpi.revenue')}</TableHeader>
                    <TableHeader align="end">ROAS</TableHeader>
                    <TableHeader align="end">{t('portfolio.col.attention')}</TableHeader>
                    <TableHeader align="end">{t('portfolio.kpi.leads')}</TableHeader>
                    <TableHeader align="end">{t('portfolio.col.budgetRisks')}</TableHeader>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {data.clients.map((client) => (
                    <TableRow key={client.id}>
                      <TableCell>
                        <ClientLink id={client.id} name={client.name} />
                      </TableCell>
                      <TableCell align="end" label={t('portfolio.kpi.spend')}>
                        <MoneyLines values={client.spend} locale={locale} />
                      </TableCell>
                      <TableCell align="end" label={t('portfolio.kpi.revenue')}>
                        <MoneyLines values={client.platform_revenue} locale={locale} />
                      </TableCell>
                      <TableCell align="end" label="ROAS">
                        <RoasLines values={client.roas} />
                      </TableCell>
                      <TableCell align="end" isNumeric label={t('portfolio.col.attention')}>
                        {client.needs_attention}
                      </TableCell>
                      <TableCell align="end" isNumeric label={t('portfolio.kpi.leads')}>
                        <Count value={client.leads} locale={locale} />
                      </TableCell>
                      <TableCell align="end" isNumeric label={t('portfolio.col.budgetRisks')}>
                        <Count value={client.budget_risks} locale={locale} />
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </DataTable>
            </CardContent>
          </Card>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>{t('portfolio.platformMix')}</CardTitle>
              </CardHeader>
              <CardContent>
                <PlatformMix mix={data.platform_mix} locale={locale} />
              </CardContent>
            </Card>
            <AttentionPanel limit={5} />
          </div>

          {data.tracking_health === null ? null : <TrackingRollup />}
        </>
      )}
    </div>
  );
}
