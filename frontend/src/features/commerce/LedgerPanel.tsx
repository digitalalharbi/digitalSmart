import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Card, CardContent } from '@/shared/ui/card';
import { InfoHint } from '@/shared/ui/info-hint';
import { formatMinor, formatNumber } from '@/shared/lib/format';
import type { RevenueLedger } from './types';

/**
 * What the counted orders were charged, what came back, and what the totals were made of.
 *
 * **The parts are shown beside the total and never subtracted from it.** Shipping, tax and fees follow each provider's own rules, so
 * "gross − discounts − refunds" would state a net this product cannot defend. What the panel does instead is say, for every part,
 * how many of the counted orders reported it: a discount that no order reported reads *Not reported*, never nought, because a provider
 * that does not publish a discount has not said there was none.
 *
 * **Reconciliation is only claimed where it was checked.** An order that reports all four parts can be added up against its total; the
 * panel says how many were checked and, where the parts do not add up, by how much — and says nothing like "reconciled" when no order
 * reported enough to check.
 */
export function LedgerPanel({ ledger, locale }: { ledger: RevenueLedger; locale: string }) {
  const { t } = useTranslation();
  const money = (minor: number): string => formatMinor(minor, ledger.currency, locale);
  const { reconciliation } = ledger;

  return (
    <Card>
      <CardContent className="flex flex-col gap-4 p-5">
        <h3 className="text-content flex items-center gap-1 text-sm font-semibold">
          {t('commerce.ledger.title')}
          <InfoHint text={t('commerce.ledger.hint')} />
        </h3>

        <table className="w-full text-sm">
          <caption className="sr-only">{t('commerce.ledger.caption')}</caption>
          <tbody>
            <tr className="border-line-subtle border-b">
              <th scope="row" className="text-content px-2 py-2 text-start font-normal">
                {t('commerce.ledger.total')}
              </th>
              <td className="text-content px-2 py-2 text-end tabular-nums">
                {money(ledger.total_minor)}
              </td>
            </tr>
            <tr className="border-line-subtle border-b">
              <th scope="row" className="text-content px-2 py-2 text-start font-normal">
                {t('commerce.ledger.refunded')}
              </th>
              <td className="text-content-secondary px-2 py-2 text-end tabular-nums">
                {money(ledger.refunded_minor)}
              </td>
            </tr>
            <tr>
              <th scope="row" className="text-content px-2 py-2 text-start font-semibold">
                {t('commerce.ledger.net')}
              </th>
              <td className="text-content px-2 py-2 text-end font-semibold tabular-nums">
                {money(ledger.net_minor)}
              </td>
            </tr>
          </tbody>
        </table>

        <table className="w-full text-sm">
          <caption className="text-content-secondary pb-1 text-start text-xs">
            {t('commerce.ledger.partsCaption')}
          </caption>
          <thead>
            <tr className="text-content-tertiary border-line-subtle border-b text-xs">
              <th scope="col" className="px-2 py-2 text-start font-medium">
                {t('commerce.ledger.part')}
              </th>
              <th scope="col" className="px-2 py-2 text-end font-medium">
                {t('commerce.ledger.amount')}
              </th>
              <th scope="col" className="px-2 py-2 text-end font-medium">
                {t('commerce.ledger.reported')}
              </th>
            </tr>
          </thead>
          <tbody>
            {ledger.components.map((component) => (
              <tr key={component.key} className="border-line-subtle border-b last:border-0">
                <th scope="row" className="text-content px-2 py-2 text-start font-normal">
                  {t(`commerce.ledger.parts.${component.key}`)}
                </th>
                <td className="text-content-secondary px-2 py-2 text-end tabular-nums">
                  {component.amount_minor === null
                    ? t('commerce.ledger.notReported')
                    : money(component.amount_minor)}
                </td>
                <td className="text-content-tertiary px-2 py-2 text-end text-xs tabular-nums">
                  {t('commerce.ledger.coverage', {
                    reporting: formatNumber(component.orders_reporting, locale),
                    counted: formatNumber(component.orders_counted, locale),
                  })}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        {reconciliation.reconciles === null ? (
          <Alert tone="info">{t('commerce.ledger.nothingToCheck')}</Alert>
        ) : reconciliation.reconciles ? (
          <p className="text-content-secondary text-sm" data-testid="ledger-reconciles">
            {t('commerce.ledger.reconciles', {
              checked: formatNumber(reconciliation.orders_checked, locale),
            })}
          </p>
        ) : (
          <Alert tone="warning">
            {t('commerce.ledger.doesNotReconcile', {
              off: formatNumber(reconciliation.orders_off, locale),
              checked: formatNumber(reconciliation.orders_checked, locale),
              amount: money(reconciliation.unexplained_minor ?? 0),
            })}
          </Alert>
        )}
      </CardContent>
    </Card>
  );
}
