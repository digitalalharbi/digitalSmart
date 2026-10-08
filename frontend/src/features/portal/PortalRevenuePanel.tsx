import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  BreakdownTable,
  CustomerMixPanel,
  RevenueChart,
  formatMinor,
  summaryMetrics,
  type RevenueSourceValue,
} from '@/features/commerce';
import { Alert } from '@/shared/ui/alert';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { MetricCard } from '@/shared/ui/metric-card';
import { Skeleton } from '@/shared/ui/skeleton';
import { usePortalRevenueQuery } from './usePortal';

/**
 * The client's own store revenue, under one named authority.
 *
 * **Nothing is shown until the client chooses who is counting.** That is not friction for its own sake: a Salla
 * total counts invoices and a GA4 total counts what a browser reported before a payment may have failed, and a
 * panel that picked one silently would be a panel showing one figure labelled as the other.
 *
 * The chooser is built from the server's own refusal, so it cannot list a source the surface would reject —
 * attributed revenue among them, which is a model and is never a portal figure.
 */
export function PortalRevenuePanel({ clientId }: { clientId: string }) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [source, setSource] = useState<RevenueSourceValue | null>(null);

  const revenue = usePortalRevenueQuery(clientId, source);
  const analytics = revenue.data?.analytics ?? null;
  const available = revenue.data?.available ?? [];
  const summary = analytics?.summary;

  return (
    <div className="flex flex-col gap-5">
      {available.length === 0 && source === null ? null : (
        <ChoiceGroup
          label={t('myPortal.revenue.whoIsCounting')}
          hint={t('myPortal.revenue.whoIsCountingHint')}
        >
          <div className="flex flex-wrap gap-2">
            {available.map((entry) => (
              <Choice
                key={entry.revenue_source}
                isSelected={source === entry.revenue_source}
                onSelect={() => setSource(entry.revenue_source)}
              >
                {entry.label}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>
      )}

      {revenue.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : source === null ? (
        <EmptyState
          title={t('myPortal.revenue.chooseTitle')}
          description={t('myPortal.revenue.chooseBody')}
        />
      ) : summary === undefined || analytics === null ? null : (
        <>
          {/*
            What this total is, in words. An invoiced figure and a measured one are different kinds of claim, and
            a reader about to quote one deserves to be told which they have.
          */}
          <Alert tone="info">
            {summary.is_invoiced
              ? t('commerce.claim.invoiced', { source: summary.revenue_source_label })
              : t('commerce.claim.reported', { source: summary.revenue_source_label })}
          </Alert>

          {summary.orders === 0 ? (
            <EmptyState
              title={t('commerce.empty.title', { source: summary.revenue_source_label })}
              description={t('commerce.empty.body')}
            />
          ) : (
            <>
              <section
                className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
                aria-label={t('commerce.summary')}
              >
                {summaryMetrics(summary, {
                  netRevenue: t('commerce.metrics.netRevenue'),
                  orders: t('commerce.metrics.orders'),
                  aov: t('commerce.metrics.aov'),
                  customers: t('commerce.metrics.customers'),
                  noOrders: t('commerce.metrics.noOrders'),
                }).map((metric) => (
                  <MetricCard key={metric.metric} value={metric} />
                ))}
              </section>

              {/* Refunds beside sales, never netted away invisibly — see `RevenueChart`. */}
              <p className="text-content-secondary text-sm">
                {t('commerce.metrics.netRevenueHint', {
                  gross: formatMinor(summary.revenue_minor, summary.currency, locale),
                  refunded: formatMinor(summary.refunded_minor, summary.currency, locale),
                  units: new Intl.NumberFormat(locale).format(summary.units),
                })}
              </p>

              <RevenueChart points={analytics.daily} currency={summary.currency} locale={locale} />

              {/*
                The same split the agency sees, on the same figures. A client who is told a campaign brought
                revenue deserves to know whether it brought customers, and the third bucket — people buying
                before this store was connected — keeps the answer honest on a young connection.
              */}
              <CustomerMixPanel
                mix={analytics.customer_mix}
                currency={summary.currency}
                locale={locale}
              />

              <BreakdownTable
                title={t('commerce.byChannel')}
                rows={analytics.by_channel}
                currency={summary.currency}
                locale={locale}
              />

              <BreakdownTable
                title={t('commerce.byProduct')}
                rows={analytics.by_product}
                currency={summary.currency}
                locale={locale}
                showUnits
              />
            </>
          )}
        </>
      )}
    </div>
  );
}
