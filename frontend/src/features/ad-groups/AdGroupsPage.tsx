import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { useClientsQuery } from '@/features/clients';
import { ProviderLogo, useProvidersQuery } from '@/features/integrations';
import {
  BudgetBar,
  HEALTH_TO_STATE,
  money,
  resultName,
  resultText,
  statusName,
} from '@/features/campaign-control';
import { SavedViewsMenu } from '@/features/surface-views';
import { cn } from '@/shared/lib/cn';
import { formatMinor, formatPercent } from '@/shared/lib/format';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { FilterSelect } from '@/shared/ui/filter-select';
import { HealthPill } from '@/shared/ui/health';
import { Input } from '@/shared/ui/input';
import { KpiTile } from '@/shared/ui/kpi-tile';
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
import { PAGE_SIZE } from './api';
import { DEFAULT_FILTERS, PERIODS, fromParams, toParams } from './filters';
import { useAdGroups } from './useAdGroups';
import type { AdGroupFilters, AdGroupSort } from './types';

const SORT_OF: Record<string, AdGroupSort> = {
  name: 'name',
  spend: 'spend',
  cost: 'cost',
  roas: 'roas',
  ctr: 'ctr',
};

/**
 * Ad Groups: the level between a campaign and its ads, as a screen of its own.
 *
 * An operator hunting for the ad set that is dragging a campaign down should not have to open the campaign first,
 * so this lists every ad group the reader may see, across clients, projects and platforms, each on its **own** figures
 * — never its campaign's, never the sum of its ads. The working context (client, project) narrows it exactly as it
 * narrows Campaigns, and the address carries every filter so a view can be shared.
 */
export function AdGroupsPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [params, setParams] = useSearchParams();
  const { context, setContext } = useUiStore();
  const sharedDays = useUiStore((state) => state.period.days);
  const fromUrl = fromParams(params);
  const filters: AdGroupFilters = {
    ...fromUrl,
    client: fromUrl.client ?? context.client,
    project: fromUrl.project ?? context.project,
  };
  const { data, isPending, isError, isFetching } = useAdGroups(
    filters.days === null ? { ...filters, days: sharedDays } : filters,
  );
  const providers = useProvidersQuery();
  const [clientTerm, setClientTerm] = useState('');
  const clients = useClientsQuery({ search: clientTerm, per_page: 8 });
  const campaignName = filters.campaign?.label ?? null;

  const update = (patch: Partial<AdGroupFilters>) => {
    const resets = Object.keys(patch).some((key) => key !== 'offset');

    setParams(
      toParams({
        ...filters,
        ...patch,
        ...(resets && patch.offset === undefined ? { offset: 0 } : {}),
      }),
      { replace: true },
    );
  };

  const isFiltered =
    filters.client !== null ||
    filters.project !== null ||
    filters.campaign !== null ||
    filters.mine ||
    filters.provider !== null ||
    filters.status !== null ||
    filters.zeroResults ||
    filters.search.trim() !== '';

  const sortBy = (sort: AdGroupSort) =>
    update(
      sort === filters.sort ? { descending: !filters.descending } : { sort, descending: false },
    );

  const heading = (id: string, text: string, end = false) => {
    const sort = SORT_OF[id];

    if (sort === undefined) {
      return <TableHeader align={end ? 'end' : 'start'}>{text}</TableHeader>;
    }

    const active = filters.sort === sort;
    const Icon = !active ? ArrowUpDown : filters.descending ? ArrowDown : ArrowUp;

    return (
      <TableHeader
        align={end ? 'end' : 'start'}
        aria-sort={!active ? 'none' : filters.descending ? 'descending' : 'ascending'}
      >
        <button
          type="button"
          onClick={() => sortBy(sort)}
          className={cn(
            'hover:text-content focus-visible:ring-accent/40 inline-flex items-center gap-1 rounded focus-visible:ring-2 focus-visible:outline-none',
            active && 'text-content',
          )}
        >
          {text}
          <Icon aria-hidden className="size-3" />
        </button>
      </TableHeader>
    );
  };

  return (
    <div className="ds-page">
      <PageHeader
        title={t('adGroups.title')}
        actions={
          <SavedViewsMenu
            surface="ad_groups"
            current={Object.fromEntries(toParams({ ...filters, offset: 0 }))}
            project={
              filters.project === null
                ? null
                : { id: filters.project.id, label: filters.project.label }
            }
            presets={[
              {
                key: 'spend',
                label: t('adGroups.preset.spend'),
                filters: { sort: 'spend' },
              },
              { key: 'roas', label: t('adGroups.preset.roas'), filters: { sort: 'roas' } },
              {
                key: 'noResults',
                label: t('adGroups.preset.noResults'),
                filters: { zero: '1' },
              },
              { key: 'mine', label: t('adGroups.preset.mine'), filters: { mine: '1' } },
            ]}
            onApply={(saved) => setParams(new URLSearchParams(saved), { replace: true })}
          />
        }
      />

      {isError ? <Alert tone="danger">{t('adGroups.loadFailed')}</Alert> : null}

      <section
        aria-label={t('adGroups.summary')}
        className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4"
      >
        <KpiTile
          highlight
          label={t('adGroups.matched')}
          value={data === undefined ? '—' : String(data.meta.matched)}
        />
        {/* Per currency, never added across them: two currencies' totals are not one number. */}
        {(data?.meta.spend_by_currency ?? []).map((entry) => (
          <KpiTile
            key={entry.currency}
            label={t('adGroups.spendIn', { currency: entry.currency })}
            value={formatMinor(entry.spend_minor, entry.currency, locale)}
          />
        ))}
      </section>

      {data?.meta.is_truncated === true ? (
        <p className="text-content-tertiary text-xs">{t('adGroups.truncated')}</p>
      ) : null}

      <section aria-label={t('adGroups.filters')} className="flex flex-col gap-3">
        <div className="bg-surface flex flex-wrap items-end gap-3 rounded-3xl p-4 shadow-xs">
          <div className="min-w-56 flex-1">
            <label htmlFor="ag-search" className="text-content-secondary text-xs font-medium">
              {t('adGroups.search')}
            </label>
            <Input
              id="ag-search"
              type="search"
              value={filters.search}
              placeholder={t('adGroups.searchPlaceholder')}
              onChange={(event) => update({ search: event.target.value })}
            />
          </div>
          <FilterSelect
            label={t('campaignControl.period')}
            value={filters.days === null ? null : String(filters.days)}
            onChange={(days) => update({ days: days === null ? null : Number(days) })}
            options={PERIODS.map((days) => ({
              value: String(days),
              label: t('campaignControl.lastDays', { count: days }),
            }))}
            any={t('campaignControl.sharedPeriod', { count: sharedDays })}
          />
          <EntityPicker
            label={t('campaignControl.client')}
            clearLabel={t('campaignControl.clear')}
            value={filters.client}
            onChange={(option) => {
              if (option === null) {
                setContext({ ...context, client: null, project: null });
              }

              update({ client: option === null ? null : { id: option.id, label: option.label } });
            }}
            onSearch={setClientTerm}
            options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
            isLoading={clients.isFetching}
          />
          <FilterSelect
            label={t('campaignControl.provider')}
            value={filters.provider}
            onChange={(provider) => update({ provider })}
            options={(providers.data ?? []).map((p) => ({ value: p.provider, label: p.label }))}
            any={t('campaignControl.any')}
          />
          <FilterSelect
            label={t('campaignControl.status')}
            value={filters.status}
            onChange={(status) => update({ status })}
            options={(data?.meta.statuses ?? []).map((s) => ({
              value: s.status,
              label: t(`campaignControl.statusName.${s.status}`, { defaultValue: s.label }),
            }))}
            any={t('campaignControl.any')}
          />
          <label className="flex h-9 items-center gap-2 self-end text-sm">
            <input
              type="checkbox"
              checked={filters.zeroResults}
              onChange={(event) => update({ zeroResults: event.target.checked })}
            />
            {t('campaignControl.zeroResults')}
          </label>
          <label className="flex h-9 items-center gap-2 self-end text-sm">
            <input
              type="checkbox"
              checked={filters.mine}
              onChange={(event) => update({ mine: event.target.checked })}
            />
            {t('campaignControl.mine')}
          </label>
        </div>
        {campaignName === null ? null : (
          <div className="flex items-center gap-2 text-sm">
            <Badge tone="neutral">
              {t('adGroups.inCampaign')} <span dir="auto">{campaignName}</span>
            </Badge>
            <Button variant="ghost" onClick={() => update({ campaign: null })}>
              {t('campaignControl.clear')}
            </Button>
          </div>
        )}
      </section>

      {isPending ? (
        <div role="status" aria-label={t('adGroups.loading')} className="flex flex-col gap-3">
          {Array.from({ length: 6 }, (_, index) => (
            <Skeleton key={index} className="h-12" />
          ))}
        </div>
      ) : data === undefined || data.data.length === 0 ? (
        <EmptyState
          title={t(isFiltered ? 'adGroups.empty.filtered' : 'adGroups.empty.none')}
          description={t('adGroups.empty.body')}
          action={
            <Button
              variant="secondary"
              onClick={() => setParams(toParams(DEFAULT_FILTERS), { replace: true })}
            >
              {t('campaignControl.reset')}
            </Button>
          }
        />
      ) : (
        <DataTable label={t('adGroups.title')} minWidth="72rem" maxHeight="70vh">
          <TableHead sticky>
            <TableRow>
              <TableHeader>{t('campaignControl.col.platform')}</TableHeader>
              {heading('name', t('adGroups.col.adGroup'))}
              <TableHeader>{t('adGroups.col.campaign')}</TableHeader>
              <TableHeader>{t('campaignControl.col.client')}</TableHeader>
              <TableHeader>{t('campaignControl.col.project')}</TableHeader>
              <TableHeader>{t('campaignControl.col.status')}</TableHeader>
              <TableHeader align="end">{t('campaignControl.col.budget')}</TableHeader>
              {heading('spend', t('campaignControl.col.spend'), true)}
              <TableHeader align="end">{t('campaignControl.col.result')}</TableHeader>
              {heading('cost', t('campaignControl.col.costPerResult'), true)}
              {heading('ctr', t('campaignControl.col.ctr'), true)}
              {heading('roas', 'ROAS', true)}
              <TableHeader>{t('adGroups.col.campaignHealth')}</TableHeader>
            </TableRow>
          </TableHead>
          <TableBody aria-busy={isFetching}>
            {data.data.map((row) => (
              <TableRow key={row.id} isInteractive>
                <TableCell label={t('campaignControl.col.platform')}>
                  <span className="flex items-center gap-2">
                    <ProviderLogo provider={row.provider as never} className="size-6" />
                    <span className="text-content-secondary text-xs">
                      {providers.data?.find((p) => p.provider === row.provider)?.label ??
                        row.provider}
                    </span>
                  </span>
                </TableCell>
                <TableCell>
                  <Link
                    to={`/ad-groups/${row.id}`}
                    className="text-content text-sm font-medium hover:underline"
                    dir="auto"
                  >
                    {row.name}
                  </Link>
                </TableCell>
                <TableCell label={t('adGroups.col.campaign')}>
                  <Link
                    to={`/campaigns/${row.campaign.id}`}
                    className="text-content-secondary text-sm hover:underline"
                    dir="auto"
                  >
                    {row.campaign.name}
                  </Link>
                </TableCell>
                <TableCell label={t('campaignControl.col.client')}>
                  {row.client_name ?? '—'}
                </TableCell>
                <TableCell label={t('campaignControl.col.project')}>
                  {row.project_name ?? '—'}
                </TableCell>
                <TableCell label={t('campaignControl.col.status')}>
                  <Badge tone="neutral">{statusName(t, row)}</Badge>
                </TableCell>
                <TableCell isNumeric align="end" label={t('campaignControl.col.budget')}>
                  <span className="flex min-w-24 flex-col items-end gap-1">
                    <span>{money(row.budget_minor, row.currency, locale)}</span>
                    {row.budget_minor === null ? null : <BudgetBar row={row} />}
                  </span>
                </TableCell>
                <TableCell isNumeric align="end" label={t('campaignControl.col.spend')}>
                  {money(row.spend_minor, row.currency, locale)}
                </TableCell>
                <TableCell isNumeric align="end" label={t('campaignControl.col.result')}>
                  <span className="flex flex-col items-end">
                    <span>{resultText(row, locale)}</span>
                    <span className="text-content-tertiary text-xs">{resultName(t, row)}</span>
                  </span>
                </TableCell>
                <TableCell isNumeric align="end" label={t('campaignControl.col.costPerResult')}>
                  {money(row.cost_per_result_minor, row.currency, locale)}
                </TableCell>
                <TableCell isNumeric align="end" label={t('campaignControl.col.ctr')}>
                  {row.ctr === null ? '—' : formatPercent(row.ctr, locale, 2)}
                </TableCell>
                <TableCell isNumeric align="end" label="ROAS">
                  {row.roas === null ? '—' : `${row.roas.toFixed(2)}×`}
                </TableCell>
                <TableCell label={t('adGroups.col.campaignHealth')}>
                  {row.campaign_health === null ? (
                    '—'
                  ) : (
                    <HealthPill state={HEALTH_TO_STATE[row.campaign_health.health]} />
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </DataTable>
      )}

      {data !== undefined && data.meta.matched > PAGE_SIZE ? (
        <nav
          aria-label={t('campaignControl.pagination')}
          className="flex items-center justify-between gap-3"
        >
          <Button
            variant="secondary"
            disabled={filters.offset === 0}
            onClick={() => update({ offset: Math.max(0, filters.offset - PAGE_SIZE) })}
          >
            {t('campaignControl.previous')}
          </Button>
          <span className="text-content-secondary text-sm">
            {t('campaignControl.range', {
              from: filters.offset + 1,
              to: Math.min(filters.offset + PAGE_SIZE, data.meta.matched),
              total: data.meta.matched,
            })}
          </span>
          <Button
            variant="secondary"
            disabled={filters.offset + PAGE_SIZE >= data.meta.matched}
            onClick={() => update({ offset: filters.offset + PAGE_SIZE })}
          >
            {t('campaignControl.next')}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}
