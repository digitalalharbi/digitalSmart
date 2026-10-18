import { LayoutGrid, Rows3, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import { useUiStore } from '@/shared/store/ui-store';
import { Link, useSearchParams } from 'react-router';
import { useClientsQuery } from '@/features/clients';
import { useProjectsQuery } from '@/features/projects';
import { useProvidersQuery } from '@/features/integrations';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { buttonVariants } from '@/shared/ui/button.variants';
import { EmptyState } from '@/shared/ui/empty-state';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { Input } from '@/shared/ui/input';
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
import { CampaignCard, CampaignIdentity, StatusPair, BudgetBar } from './CampaignParts';
import { money, resultText } from './text';
import { SavedViewsMenu } from '@/features/surface-views';
import { OwnershipQueue } from './OwnershipQueue';
import { CampaignsHero } from './CampaignsHero';
import { DEFAULT_FILTERS, PERIODS, fromParams, toParams } from './filters';
import { useCampaignControl } from './useCampaignControl';
import type { ControlFilters, SortKey } from './types';

/**
 * The Campaign Control Center.
 *
 * One page answering "which of our campaigns needs me", across every client, project, provider and account
 * the reader may see — so that daily monitoring does not mean opening six advertising platforms.
 *
 * Everything is the server's: filtering, sorting, paging and the health verdicts. The browser holds one
 * page of 24 and the counts that describe the rest.
 */
export function CampaignControlPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [params, setParams] = useSearchParams();
  const { context, setContext } = useUiStore();
  const fromUrl = fromParams(params);
  /*
   * The working context fills in a client or project the address bar does not name. The URL still wins:
   * a link somebody pasted must show what its sender saw, whatever the reader happens to be working inside.
   */
  const filters: ControlFilters = {
    ...fromUrl,
    client: fromUrl.client ?? context.client,
    project: fromUrl.project ?? context.project,
  };
  const { data, isPending, isError, isFetching } = useCampaignControl(filters);
  /* Open when an address already narrows by one of the extra filters, so nothing in force is hidden. */
  const [moreOpen, setMoreOpen] = useState(
    () =>
      filters.mine ||
      filters.project !== null ||
      filters.account !== null ||
      filters.status !== null ||
      filters.objective !== null ||
      filters.tag !== null,
  );

  const [clientTerm, setClientTerm] = useState('');
  const [projectTerm, setProjectTerm] = useState('');
  const clients = useClientsQuery({ search: clientTerm, per_page: 8 });
  const projects = useProjectsQuery({ search: projectTerm, per_page: 8 });
  const [accountTerm, setAccountTerm] = useState('');
  const accounts = useQuery({
    queryKey: ['campaign-control', 'accounts', accountTerm] as const,
    queryFn: async (): Promise<Array<{ id: string; label: string; context: string | null }>> => {
      const { data: body } = await api.get<{
        data: Array<{ id: string; label: string; context: string | null }>;
      }>('/search', { params: { q: accountTerm, type: ['account'], limit: 8 } });

      return body.data;
    },
    enabled: accountTerm.trim().length >= 2,
    staleTime: 30_000,
    retry: false,
  });
  const providers = useProvidersQuery();
  const tags = useQuery({
    queryKey: ['tags'] as const,
    queryFn: async (): Promise<Array<{ id: string; name: string }>> => {
      const { data: body } = await api.get<{ data: Array<{ id: string; name: string }> }>('/tags');

      return body.data;
    },
    staleTime: 60_000,
    retry: false,
  });

  const update = (patch: Partial<ControlFilters>) => {
    /* Any filter change goes back to the first page; staying on page 9 of a different list is a blank screen. */
    const resets = Object.keys(patch).some((key) => key !== 'offset' && key !== 'view');
    setParams(
      toParams({
        ...filters,
        ...patch,
        ...(resets && patch.offset === undefined ? { offset: 0 } : {}),
      }),
      {
        replace: true,
      },
    );
  };

  /* "No campaigns yet" and "none match" are different messages, and only the filters tell them apart. */
  const isFiltered =
    filters.client !== null ||
    filters.project !== null ||
    filters.account !== null ||
    filters.mine ||
    filters.provider !== null ||
    filters.status !== null ||
    filters.objective !== null ||
    filters.health !== null ||
    filters.search.trim() !== '';

  return (
    <div className="ds-page">
      <PageHeader
        title={t('campaignControl.title')}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <SavedViewsMenu
              surface="campaign_control"
              current={Object.fromEntries(toParams({ ...filters, offset: 0 }))}
              project={
                filters.project === null
                  ? null
                  : { id: filters.project.id, label: filters.project.label }
              }
              presets={[
                {
                  key: 'critical',
                  label: t('campaignControl.preset.critical'),
                  filters: { health: 'critical' },
                },
                {
                  key: 'attention',
                  label: t('campaignControl.preset.attention'),
                  filters: { health: 'needs_attention' },
                },
                { key: 'mine', label: t('campaignControl.preset.mine'), filters: { mine: '1' } },
                {
                  key: 'google',
                  label: t('campaignControl.preset.google'),
                  filters: { provider: 'google_ads' },
                },
                {
                  key: 'meta',
                  label: t('campaignControl.preset.meta'),
                  filters: { provider: 'meta' },
                },
              ]}
              onApply={(saved) => setParams(new URLSearchParams(saved), { replace: true })}
            />
            <Link to="/campaigns/explore" className={buttonVariants({ variant: 'secondary' })}>
              {t('campaignControl.explore')}
            </Link>
          </div>
        }
      />

      {isError ? <Alert tone="danger">{t('campaignControl.loadFailed')}</Alert> : null}

      {/* Health is judged over the chosen window here; a campaign's own page and the daily queue read 28 days. */}
      {filters.days !== null && filters.days !== 28 ? (
        <Alert tone="info">{t('campaignControl.otherWindow', { days: filters.days })}</Alert>
      ) : null}

      <CampaignsHero
        meta={data?.meta}
        selected={filters.health}
        onSelect={(health) => update({ health })}
        showMix={
          filters.client === null &&
          filters.project === null &&
          filters.account === null &&
          filters.provider === null &&
          !filters.mine
        }
      />

      {data?.meta.is_truncated === true ? (
        <p className="text-content-tertiary text-xs">
          {t('campaignControl.truncated', { assessed: data.meta.assessed, total: data.meta.total })}
        </p>
      ) : null}

      <section aria-label={t('campaignControl.filters')} className="flex flex-col gap-3">
        <div className="bg-surface flex flex-wrap items-end gap-3 rounded-3xl p-4 shadow-xs">
          <div className="min-w-56 flex-1">
            <label htmlFor="cc-search" className="text-content-secondary text-xs font-medium">
              {t('campaignControl.search')}
            </label>
            <Input
              id="cc-search"
              type="search"
              value={filters.search}
              placeholder={t('campaignControl.searchPlaceholder')}
              onChange={(event) => update({ search: event.target.value })}
            />
          </div>
          <Select
            label={t('campaignControl.period')}
            value={filters.days === null ? null : String(filters.days)}
            onChange={(days) => update({ days: days === null ? null : Number(days) })}
            options={PERIODS.map((days) => ({
              value: String(days),
              label: t('campaignControl.lastDays', { count: days }),
            }))}
            any={t('campaignControl.defaultPeriod')}
          />
          <EntityPicker
            label={t('campaignControl.client')}
            clearLabel={t('campaignControl.clear')}
            value={filters.client}
            onChange={(o) => {
              if (o === null) {
                setContext({ ...context, client: null, project: null });
              }

              update({ client: o === null ? null : { id: o.id, label: o.label } });
            }}
            onSearch={setClientTerm}
            options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
            isLoading={clients.isFetching}
          />
          <Select
            label={t('campaignControl.provider')}
            value={filters.provider}
            onChange={(provider) => update({ provider })}
            options={(providers.data ?? []).map((p) => ({ value: p.provider, label: p.label }))}
            any={t('campaignControl.any')}
          />
          <Button
            variant="secondary"
            aria-expanded={moreOpen}
            onClick={() => setMoreOpen((open) => !open)}
            className="self-end"
          >
            <SlidersHorizontal />
            {t('campaignControl.moreFilters')}
          </Button>
          <div className="flex gap-1" role="group" aria-label={t('campaignControl.view')}>
            <Button
              variant={filters.view === 'cards' ? 'secondary' : 'ghost'}
              size="icon"
              aria-pressed={filters.view === 'cards'}
              aria-label={t('campaignControl.cards')}
              onClick={() => update({ view: 'cards' })}
            >
              <LayoutGrid />
            </Button>
            <Button
              variant={filters.view === 'table' ? 'secondary' : 'ghost'}
              size="icon"
              aria-pressed={filters.view === 'table'}
              aria-label={t('campaignControl.table')}
              onClick={() => update({ view: 'table' })}
            >
              <Rows3 />
            </Button>
          </div>
        </div>
        {moreOpen ? (
          <div className="bg-surface flex flex-wrap items-end gap-3 rounded-3xl p-4 shadow-xs">
            <EntityPicker
              label={t('campaignControl.project')}
              clearLabel={t('campaignControl.clear')}
              value={filters.project}
              onChange={(o) => {
                if (o === null) {
                  setContext({ ...context, project: null });
                }

                update({ project: o === null ? null : { id: o.id, label: o.label } });
              }}
              onSearch={setProjectTerm}
              options={(projects.data?.data ?? []).map((p) => ({ id: p.id, label: p.name }))}
              isLoading={projects.isFetching}
            />
            <EntityPicker
              label={t('campaignControl.account')}
              clearLabel={t('campaignControl.clear')}
              value={filters.account}
              onChange={(o) =>
                update({ account: o === null ? null : { id: o.id, label: o.label } })
              }
              onSearch={setAccountTerm}
              options={(accounts.data ?? []).map((a) => ({
                id: a.id,
                label: a.label,
                context: a.context,
              }))}
              isLoading={accounts.isFetching}
            />
            <label className="flex h-9 items-center gap-2 self-end text-sm">
              <input
                type="checkbox"
                checked={filters.mine}
                onChange={(event) => update({ mine: event.target.checked })}
              />
              {t('campaignControl.mine')}
            </label>
            {(tags.data ?? []).length === 0 ? null : (
              <Select
                label={t('campaignControl.tag')}
                value={filters.tag?.id ?? null}
                onChange={(id) =>
                  update({
                    tag:
                      id === null
                        ? null
                        : { id, label: (tags.data ?? []).find((tag) => tag.id === id)?.name ?? '' },
                  })
                }
                options={(tags.data ?? []).map((tag) => ({ value: tag.id, label: tag.name }))}
                any={t('campaignControl.any')}
              />
            )}
            <Select
              label={t('campaignControl.status')}
              value={filters.status}
              onChange={(status) => update({ status })}
              options={(data?.meta.statuses ?? []).map((s) => ({
                value: s.status,
                label: s.label,
              }))}
              any={t('campaignControl.any')}
            />
            <Select
              label={t('campaignControl.objective')}
              value={filters.objective}
              onChange={(objective) => update({ objective })}
              options={(data?.meta.objectives ?? []).map((o) => ({
                value: o.objective,
                label: o.label,
              }))}
              any={t('campaignControl.any')}
            />{' '}
            <Select
              label={t('campaignControl.sort')}
              value={filters.sort}
              onChange={(sort) => update({ sort: (sort ?? 'health') as SortKey })}
              options={(['health', 'spend', 'cost', 'roas', 'name'] as const).map((key) => ({
                value: key,
                label: t(`campaignControl.sortBy.${key}`),
              }))}
              any={null}
            />
          </div>
        ) : null}
      </section>

      {isPending ? (
        <div
          role="status"
          aria-label={t('campaignControl.loading')}
          className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
        >
          {Array.from({ length: 6 }, (_, i) => (
            <Skeleton key={i} className="h-56" />
          ))}
        </div>
      ) : data === undefined || data.data.length === 0 ? (
        <EmptyState
          title={t(isFiltered ? 'campaignControl.empty.filtered' : 'campaignControl.empty.none')}
          description={t('campaignControl.empty.body')}
          action={
            <Button
              variant="secondary"
              onClick={() => setParams(toParams(DEFAULT_FILTERS), { replace: true })}
            >
              {t('campaignControl.reset')}
            </Button>
          }
        />
      ) : filters.view === 'cards' ? (
        <ul aria-busy={isFetching} className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {data.data.map((row) => (
            <li key={row.id}>
              <CampaignCard row={row} />
            </li>
          ))}
        </ul>
      ) : (
        <DataTable label={t('campaignControl.title')} minWidth="64rem">
          <TableHead>
            <TableRow>
              <TableHeader>{t('campaignControl.col.campaign')}</TableHeader>
              <TableHeader>{t('campaignControl.col.health')}</TableHeader>
              <TableHeader align="end">{t('campaignControl.col.spend')}</TableHeader>
              <TableHeader align="end">{t('campaignControl.col.result')}</TableHeader>
              <TableHeader align="end">{t('campaignControl.col.costPerResult')}</TableHeader>
              <TableHeader align="end">ROAS</TableHeader>
              <TableHeader>{t('campaignControl.col.budget')}</TableHeader>
            </TableRow>
          </TableHead>
          <TableBody>
            {data.data.map((row) => (
              <TableRow key={row.id}>
                <TableCell>
                  <CampaignIdentity row={row} />
                </TableCell>
                <TableCell label={t('campaignControl.col.health')}>
                  <StatusPair row={row} />
                </TableCell>
                <TableCell isNumeric align="end" label={t('campaignControl.col.spend')}>
                  {money(row.spend_minor, row.currency, locale)}
                </TableCell>
                <TableCell isNumeric align="end" label={t('campaignControl.col.result')}>
                  {resultText(row, locale)}
                </TableCell>
                <TableCell isNumeric align="end" label={t('campaignControl.col.costPerResult')}>
                  {money(row.cost_per_result_minor, row.currency, locale)}
                </TableCell>
                <TableCell isNumeric align="end" label="ROAS">
                  {row.roas === null ? '—' : `${row.roas.toFixed(2)}×`}
                </TableCell>
                <TableCell label={t('campaignControl.col.budget')}>
                  <BudgetBar row={row} />
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
      {/* Shows itself only when campaigns are waiting to be placed; closed, because it is housekeeping, not the picture. */}
      <OwnershipQueue />
    </div>
  );
}

function Select({
  label,
  value,
  onChange,
  options,
  any,
}: {
  label: string;
  value: string | null;
  onChange: (value: string | null) => void;
  options: Array<{ value: string; label: string }>;
  any: string | null;
}) {
  const id = `cc-${label}`;

  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-content-secondary text-xs font-medium">
        {label}
      </label>
      <select
        id={id}
        value={value ?? ''}
        onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
        className="bg-surface-sunken text-content h-10 rounded-full border-0 px-4 text-sm"
      >
        {any === null ? null : <option value="">{any}</option>}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  );
}
