import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useClientsQuery } from '@/features/clients';
import { formatMinor } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { EntityPicker } from '@/shared/ui/entity-picker';
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
import { useStoreRollup } from './useStoreRollup';

/** Only the sources an order can carry: a store's own authority, never a modelled or reported one. */
const SOURCES = ['salla', 'zid'] as const;
const PERIODS = [7, 30, 90] as const;

/**
 * Every store the reader may see, one row each.
 *
 * Money is shown **per store, in the store's own currency**, and never totalled: two stores can be in two
 * currencies, and two in one still belong to two different clients. A store that sold nothing still has a
 * row — "quiet" is something an agency wants to see, and its average order value is a dash, not a zero.
 */
export function StoresRollupPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [source, setSource] = useState<(typeof SOURCES)[number]>('salla');
  const [days, setDays] = useState<(typeof PERIODS)[number]>(30);
  const [client, setClient] = useState<{ id: string; label: string } | null>(null);
  const [term, setTerm] = useState('');
  const clients = useClientsQuery({ search: term, per_page: 8 });
  const rollup = useStoreRollup({ revenueSource: source, days, clientId: client?.id ?? null });

  const money = (minor: number | null, currency: string) =>
    minor === null ? '—' : formatMinor(minor, currency, locale);

  return (
    <div className="ds-page">
      <PageHeader
        title={t('commerceRollup.title')}
        description={t('commerceRollup.subtitle')}
        filters={
          <div className="flex flex-wrap items-end gap-6">
            <ChoiceGroup label={t('commerceRollup.source')}>
              <div className="flex gap-2">
                {SOURCES.map((value) => (
                  <Choice
                    key={value}
                    isSelected={source === value}
                    onSelect={() => setSource(value)}
                  >
                    {t(`commerce.sources.${value}`)}
                  </Choice>
                ))}
              </div>
            </ChoiceGroup>
            <ChoiceGroup label={t('commerce.period.label')}>
              <div className="flex gap-2">
                {PERIODS.map((value) => (
                  <Choice key={value} isSelected={days === value} onSelect={() => setDays(value)}>
                    {t('commerce.period.days', { count: value })}
                  </Choice>
                ))}
              </div>
            </ChoiceGroup>
            <EntityPicker
              label={t('commerce.client')}
              clearLabel={t('commerceRollup.clear')}
              value={client}
              onChange={(o) => setClient(o === null ? null : { id: o.id, label: o.label })}
              onSearch={setTerm}
              options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
              isLoading={clients.isFetching}
            />
          </div>
        }
      />

      {rollup.isError ? <Alert tone="danger">{t('commerceRollup.loadFailed')}</Alert> : null}

      {rollup.isPending ? (
        <Skeleton className="h-48 w-full" />
      ) : (rollup.data?.stores ?? []).length === 0 ? (
        <EmptyState
          title={t('commerceRollup.empty.title')}
          description={t('commerceRollup.empty.body')}
        />
      ) : (
        <DataTable label={t('commerceRollup.title')} minWidth="60rem">
          <TableHead>
            <TableRow>
              <TableHeader>{t('commerceRollup.col.store')}</TableHeader>
              <TableHeader>{t('commerceRollup.col.client')}</TableHeader>
              <TableHeader align="end">{t('commerceRollup.col.orders')}</TableHeader>
              <TableHeader align="end">{t('commerceRollup.col.revenue')}</TableHeader>
              <TableHeader align="end">{t('commerceRollup.col.net')}</TableHeader>
              <TableHeader align="end">{t('commerceRollup.col.aov')}</TableHeader>
              <TableHeader align="end">{t('commerceRollup.col.attributed')}</TableHeader>
            </TableRow>
          </TableHead>
          <TableBody>
            {(rollup.data?.stores ?? []).map((row) => (
              <TableRow key={row.store_id}>
                <TableCell label={t('commerceRollup.col.store')}>
                  <span className="flex flex-col">
                    <span className="text-content font-medium">{row.store_name}</span>
                    {row.is_active ? null : (
                      <span className="text-content-tertiary text-xs">
                        {t('commerceRollup.inactive')}
                      </span>
                    )}
                  </span>
                </TableCell>
                <TableCell label={t('commerceRollup.col.client')}>{row.client_name}</TableCell>
                <TableCell align="end" label={t('commerceRollup.col.orders')}>
                  {row.orders}
                </TableCell>
                <TableCell align="end" label={t('commerceRollup.col.revenue')}>
                  {money(row.revenue_minor, row.currency)}
                </TableCell>
                <TableCell align="end" label={t('commerceRollup.col.net')}>
                  {money(row.net_revenue_minor, row.currency)}
                </TableCell>
                <TableCell align="end" label={t('commerceRollup.col.aov')}>
                  {money(row.average_order_minor, row.currency)}
                </TableCell>
                <TableCell align="end" label={t('commerceRollup.col.attributed')}>
                  {money(row.attributed_revenue_minor, row.currency)}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </DataTable>
      )}

      <p className="text-content-tertiary text-xs">{t('commerceRollup.note')}</p>
    </div>
  );
}
