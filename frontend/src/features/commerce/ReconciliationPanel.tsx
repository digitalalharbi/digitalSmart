import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Card, CardContent } from '@/shared/ui/card';
import { InfoHint } from '@/shared/ui/info-hint';
import { SourceBadge } from '@/shared/ui/source-badge';
import { formatMinor, formatNumber, localeTag } from '@/shared/lib/format';
import { useReconciliationQuery } from './useCommerce';
import type { ReconciliationClaim } from './types';

function signed(value: number, locale: string): string {
  return new Intl.NumberFormat(localeTag(locale), {
    signDisplay: 'exceptZero',
    maximumFractionDigits: 1,
  }).format(value);
}

/**
 * One client's sales by each authority, side by side.
 *
 * **The store is the first row and the only one called sales.** Every other row is a claim, shown with how far it is from the store —
 * and there is no total row, because adding what a platform believes it caused to what a browser reported produces a number nobody
 * can defend. A difference that cannot be computed reads *Not available*, never nought: a missing figure is not agreement.
 */
export function ReconciliationPanel({
  clientId,
  from,
  until,
  locale,
}: {
  clientId: string;
  from: string;
  until: string;
  locale: string;
}) {
  const { t } = useTranslation();
  const query = useReconciliationQuery(clientId, from, until);

  if (query.isPending) {
    return null;
  }

  if (query.isError) {
    return <Alert tone="warning">{t('commerce.reconciliation.failed')}</Alert>;
  }

  const data = query.data;
  const none = t('commerce.reconciliation.notAvailable');
  const count = (value: number | null): string =>
    value === null ? none : formatNumber(value, locale, Number.isInteger(value) ? 0 : 1);
  const money = (minor: number | null, currency: string | null): string =>
    minor === null || currency === null ? none : formatMinor(minor, currency, locale);
  const moneyDelta = (claim: ReconciliationClaim): string => {
    if (claim.revenue_delta_minor === null || claim.currency === null) {
      return none;
    }

    const amount = formatMinor(Math.abs(claim.revenue_delta_minor), claim.currency, locale);

    return claim.revenue_delta_minor === 0
      ? amount
      : `${claim.revenue_delta_minor > 0 ? '+' : '−'}${amount}`;
  };

  return (
    <Card>
      <CardContent className="flex flex-col gap-4 p-5">
        <h3 className="text-content flex items-center gap-1 text-sm font-semibold">
          {t('commerce.reconciliation.title')}
          <InfoHint text={t('commerce.reconciliation.hint')} />
        </h3>

        {data.store_paid_orders === null ? (
          <Alert tone="info">{t('commerce.reconciliation.noStore')}</Alert>
        ) : null}

        <div className="overflow-x-auto">
          <table className="w-full min-w-[34rem] text-sm">
            <caption className="sr-only">{t('commerce.reconciliation.caption')}</caption>
            <thead>
              <tr className="text-content-tertiary border-line-subtle border-b text-xs">
                <th scope="col" className="px-2 py-2 text-start font-medium">
                  {t('commerce.reconciliation.who')}
                </th>
                <th scope="col" className="px-2 py-2 text-end font-medium">
                  {t('commerce.reconciliation.purchases')}
                </th>
                <th scope="col" className="px-2 py-2 text-end font-medium">
                  {t('commerce.reconciliation.vsStore')}
                </th>
                <th scope="col" className="px-2 py-2 text-end font-medium">
                  {t('commerce.reconciliation.revenue')}
                </th>
                <th scope="col" className="px-2 py-2 text-end font-medium">
                  {t('commerce.reconciliation.vsStore')}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr className="border-line-subtle border-b">
                <th scope="row" className="text-content px-2 py-2 text-start font-semibold">
                  {t('commerce.reconciliation.sources.store')}
                </th>
                <td className="text-content px-2 py-2 text-end font-semibold tabular-nums">
                  {count(data.store_paid_orders)}
                </td>
                <td className="text-content-tertiary px-2 py-2 text-end text-xs">
                  {t('commerce.reconciliation.authority')}
                </td>
                <td className="text-content px-2 py-2 text-end font-semibold tabular-nums">
                  {money(data.store_net_revenue_minor, data.store_currency)}
                </td>
                <td className="text-content-tertiary px-2 py-2 text-end text-xs">
                  {t('commerce.reconciliation.authority')}
                </td>
              </tr>
              {data.claims.map((claim) => (
                <tr key={claim.source} className="border-line-subtle border-b last:border-0">
                  <th scope="row" className="text-content px-2 py-2 text-start font-normal">
                    <span className="flex flex-wrap items-center gap-2">
                      {t(`commerce.reconciliation.sources.${claim.source}`)}
                      {claim.metric_source === null ? null : (
                        <SourceBadge source={claim.metric_source} />
                      )}
                    </span>
                  </th>
                  <td className="text-content-secondary px-2 py-2 text-end tabular-nums">
                    {count(claim.purchases)}
                  </td>
                  <td className="text-content-secondary px-2 py-2 text-end tabular-nums">
                    {claim.purchases_delta === null ? none : signed(claim.purchases_delta, locale)}
                  </td>
                  <td className="text-content-secondary px-2 py-2 text-end tabular-nums">
                    {money(claim.revenue_minor, claim.currency)}
                  </td>
                  <td className="text-content-secondary px-2 py-2 text-end tabular-nums">
                    {moneyDelta(claim)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <p className="text-content-secondary text-xs">{t('commerce.reconciliation.note')}</p>
      </CardContent>
    </Card>
  );
}
