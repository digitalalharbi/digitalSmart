import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Card, CardContent } from '@/shared/ui/card';
import { formatNumber } from '@/shared/lib/format';
import { formatMinor } from './money';
import type { CustomerMix } from './types';

/**
 * How much of a period's revenue came from customers new to the store.
 *
 * **Three buckets, always all three.** The one that matters is "started before this store was connected": a
 * store's history in this product begins when its integration was connected, so a customer whose first recorded
 * order sits at that edge may have been buying for years. Calling them new would make every freshly connected
 * store look like a triumph, and the figure would be measuring how long the integration had been running.
 *
 * **The share is withheld rather than computed** whenever that bucket is a fifth or more of the period, and the
 * server's sentence goes where the percentage would have been.
 *
 * **The table shows revenue and customers per bucket and no percentages at all.** That is deliberate and it is
 * the point of the component: `new_share` is the new bucket's share of revenue, so a share column here would
 * recompute — from numbers that must be shown — the exact figure the server refused to publish, and the
 * withholding rule would hold only in the one place a reader was not looking. The weight of the unclassified
 * bucket is legible from its revenue beside the others, which is what a reader needs to judge the split.
 *
 * No chart. Three numbers that add to a total are a table; a pie of three slices would be decoration, and one
 * slice means "we cannot tell", which no chart says well.
 */
export function CustomerMixPanel({
  mix,
  currency,
  locale,
}: {
  mix: CustomerMix;
  currency: string;
  locale: string;
}) {
  const { t } = useTranslation();

  const revenue: Record<CustomerMix['buckets'][number]['recency'], number> = {
    new: mix.new_revenue_minor,
    returning: mix.returning_revenue_minor,
    unknown: mix.unknown_revenue_minor,
  };

  const customers: Record<CustomerMix['buckets'][number]['recency'], number> = {
    new: mix.new_customers,
    returning: mix.returning_customers,
    unknown: mix.unknown_customers,
  };

  return (
    <Card>
      <CardContent className="flex flex-col gap-4 p-5">
        <div className="flex flex-wrap items-baseline justify-between gap-3">
          <h3 className="text-content text-sm font-semibold">{t('commerce.mix.title')}</h3>

          {/*
            The headline figure, or nothing. Never nought per cent: zero is a measurement and the absence of one
            is not, and a revenue report must not confuse them.
          */}
          {mix.new_share === null ? null : (
            <p
              className="text-content text-2xl font-semibold tabular-nums"
              data-testid="customer-mix-share"
            >
              {t('commerce.mix.newShare', {
                share: new Intl.NumberFormat(locale, {
                  style: 'percent',
                  maximumFractionDigits: 1,
                }).format(mix.new_share),
              })}
            </p>
          )}
        </div>

        {mix.unavailable_reason === null ? (
          <p className="text-content-secondary text-sm">{t('commerce.mix.hint')}</p>
        ) : (
          <Alert tone="info">{mix.unavailable_reason}</Alert>
        )}

        <table className="w-full text-sm">
          <caption className="sr-only">{t('commerce.mix.caption')}</caption>
          <thead>
            <tr className="text-content-tertiary border-line-subtle border-b text-xs">
              <th scope="col" className="px-2 py-2 text-start font-medium">
                {t('commerce.mix.bucket')}
              </th>
              <th scope="col" className="px-2 py-2 text-end font-medium">
                {t('commerce.mix.customers')}
              </th>
              <th scope="col" className="px-2 py-2 text-end font-medium">
                {t('commerce.mix.revenue')}
              </th>
            </tr>
          </thead>
          <tbody>
            {mix.buckets.map((bucket) => (
              <tr key={bucket.recency} className="border-line-subtle border-b last:border-0">
                {/* The server's own label, including the long one that explains the third bucket. */}
                <th scope="row" className="text-content px-2 py-2 text-start font-normal">
                  {bucket.label}
                </th>
                <td className="text-content-secondary px-2 py-2 text-end tabular-nums">
                  {formatNumber(customers[bucket.recency], locale)}
                </td>
                <td className="text-content-secondary px-2 py-2 text-end tabular-nums">
                  {formatMinor(revenue[bucket.recency], currency, locale)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}
