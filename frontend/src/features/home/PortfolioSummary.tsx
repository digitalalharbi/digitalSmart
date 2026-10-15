import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import type { Portfolio } from '@/features/portfolio';
import { buttonVariants } from '@/shared/ui/button.variants';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { HealthDistribution } from '@/shared/ui/health-distribution';
import { Skeleton } from '@/shared/ui/skeleton';

/** The agency portfolio, small: health and the headline counts, with the whole page one click away. */
export function PortfolioSummary({
  portfolio,
  isPending,
}: {
  portfolio: Portfolio | undefined;
  isPending: boolean;
}) {
  const { t } = useTranslation();

  if (isPending || portfolio === undefined) {
    return <Skeleton className="h-32 w-full" />;
  }

  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between gap-3">
        <CardTitle>{t('home.agencyHealth')}</CardTitle>
        <Link to="/portfolio" className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
          {t('home.openPortfolio')}
        </Link>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <dl className="grid grid-cols-3 gap-3 text-sm">
          <div>
            <dt className="text-content-tertiary text-xs">{t('portfolio.kpi.clients')}</dt>
            <dd className="ds-figure text-xl">{portfolio.kpis.active_clients}</dd>
          </div>
          <div>
            <dt className="text-content-tertiary text-xs">{t('portfolio.kpi.projects')}</dt>
            <dd className="ds-figure text-xl">{portfolio.kpis.active_projects}</dd>
          </div>
          <div>
            <dt className="text-content-tertiary text-xs">{t('portfolio.kpi.campaigns')}</dt>
            <dd className="ds-figure text-xl">{portfolio.kpis.active_campaigns}</dd>
          </div>
        </dl>
        <HealthDistribution
          counts={portfolio.health}
          order={[
            'critical',
            'needs_attention',
            'watch',
            'healthy',
            'insufficient_data',
            'inactive',
          ]}
          labels={{
            critical: t('health.critical'),
            needs_attention: t('health.attention'),
            watch: t('health.watch'),
            healthy: t('health.healthy'),
            insufficient_data: t('health.unknown'),
            inactive: t('health.inactive'),
          }}
        />
      </CardContent>
    </Card>
  );
}
