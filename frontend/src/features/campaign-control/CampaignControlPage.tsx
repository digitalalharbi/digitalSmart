import { LayoutGrid, Plus, Rows3, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/shared/api/client';
import { useUiStore } from '@/shared/store/ui-store';
import { Link, useSearchParams } from 'react-router';
import { PlatformPanel, useAnalytics } from '@/features/analytics';
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
import { FilterSelect } from '@/shared/ui/filter-select';
import { Skeleton } from '@/shared/ui/skeleton';
import { PAGE_SIZE } from './api';
import { CampaignCard } from './CampaignParts';
import { CampaignTable, TableSettingsMenu } from './CampaignTable';
import { readSettings, writeSettings, type TableSettings } from './columns';
import { SavedViewsMenu } from '@/features/surface-views';
import { OwnershipQueue } from './OwnershipQueue';
import { BulkBar } from './BulkBar';
import { CampaignsHero } from './CampaignsHero';
import { DEFAULT_FILTERS, ENDS_WITHIN, PERIODS, fromParams, toParams } from './filters';
import { useCampaignControl } from './useCampaignControl';
import { HEALTH_TO_STATE, type ControlFilters, type SortKey } from './types';

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
  const { t } = useTranslation();
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
  /* No period in the address: the shared period every analytical page reads. A link with `days` keeps its own. */
  const sharedDays = useUiStore((state) => state.period.days);
  const { data, isPending, isError, isFetching } = useCampaignControl(
    filters.days === null ? { ...filters, days: sharedDays } : filters,
  );
  /*
   * Where the money went, platform by platform, over the period this page is showing. The panel ignores the platform
   * filter on purpose: it is where that filter is chosen, and a panel that shrank to the platform just picked could
   * not be used to pick another. Picking one narrows the whole page — the list, the ring and the totals.
   */
  const days = filters.days ?? sharedDays;
  const platforms = useAnalytics({
    preset:
      (
        { 7: '7d', 14: '14d', 28: '28d', 30: '30d', 90: '90d' } as Record<
          number,
          '7d' | '14d' | '28d' | '30d' | '90d'
        >
      )[days] ?? '28d',
    dimension: 'provider',
    metrics: ['spend', 'conversions', 'cpa', 'roas', 'ctr'],
    order_by: 'spend',
    limit: 12,
    ...(filters.client === null ? {} : { client_id: filters.client.id }),
    ...(filters.project === null ? {} : { project_ids: [filters.project.id] }),
  });

  /* Open when an address already narrows by one of the extra filters, so nothing in force is hidden. */
  const [moreOpen, setMoreOpen] = useState(
    () =>
      filters.mine ||
      filters.project !== null ||
      filters.account !== null ||
      filters.status !== null ||
      filters.objective !== null ||
      filters.tag !== null ||
      filters.health !== null ||
      filters.budget !== null ||
      filters.endsWithin !== null,
  );

  const [clientTerm, setClientTerm] = useState('');
  /* Ticked campaigns for a bulk action. Ids only, so a re-fetch never carries a stale row into a request. */
  const [chosen, setChosen] = useState<Set<string>>(new Set());
  const toggle = (id: string) =>
    setChosen((current) => {
      const next = new Set(current);

      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }

      return next;
    });
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
  const providerLabels = new Map((providers.data ?? []).map((p) => [p.provider, p.label]));
  const [tableSettings, setTableSettings] = useState<TableSettings>(readSettings);
  const changeSettings = (next: TableSettings) => {
    setTableSettings(next);
    writeSettings(next);
  };
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
    filters.budget !== null ||
    filters.endsWithin !== null ||
    filters.zeroResults ||
    filters.search.trim() !== '';

  return (
    <div className="ds-page">
      <PageHeader
        title={t('campaignControl.title')}
        primaryAction={
          <Link to="/campaigns/new" className={buttonVariants({ variant: 'primary' })}>
            <Plus aria-hidden className="size-4" />
            {t('builder.cta_new')}
          </Link>
        }
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Link to="/campaigns/plans" className={buttonVariants({ variant: 'secondary' })}>
              {t('builder.cta_plans')}
            </Link>
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
                  key: 'active',
                  label: t('campaignControl.preset.active'),
                  filters: { status: 'active' },
                },
                {
                  key: 'attention',
                  label: t('campaignControl.preset.attention'),
                  filters: { health: 'needs_attention' },
                },
                {
                  key: 'critical',
                  label: t('campaignControl.preset.critical'),
                  filters: { health: 'critical' },
                },
                {
                  key: 'spend',
                  label: t('campaignControl.preset.spend'),
                  filters: { sort: 'spend' },
                },
                {
                  key: 'roas',
                  label: t('campaignControl.preset.roas'),
                  filters: { sort: 'roas' },
                },
                {
                  key: 'ending',
                  label: t('campaignControl.preset.ending'),
                  filters: { ends: '7' },
                },
                {
                  key: 'budget',
                  label: t('campaignControl.preset.budget'),
                  filters: { budget: 'high' },
                },
                {
                  key: 'noResults',
                  label: t('campaignControl.preset.noResults'),
                  filters: { zero: '1' },
                },
                { key: 'mine', label: t('campaignControl.preset.mine'), filters: { mine: '1' } },
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
      {(filters.days ?? sharedDays) !== 28 ? (
        <Alert tone="info">
          {t('campaignControl.otherWindow', { days: filters.days ?? sharedDays })}
        </Alert>
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

      {platforms.data === undefined ? null : (
        <PlatformPanel
          data={platforms.data}
          selected={filters.provider === null ? [] : [filters.provider]}
          onToggle={(provider) =>
            update({
              provider: filters.provider === provider ? null : provider,
            })
          }
        />
      )}

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
          <FilterSelect
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
          {filters.view === 'table' ? (
            <TableSettingsMenu settings={tableSettings} onChange={changeSettings} />
          ) : null}
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
              <FilterSelect
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
            <FilterSelect
              label={t('campaignControl.status')}
              value={filters.status}
              onChange={(status) => update({ status })}
              options={(data?.meta.statuses ?? []).map((s) => ({
                value: s.status,
                label: s.label,
              }))}
              any={t('campaignControl.any')}
            />
            <FilterSelect
              label={t('campaignControl.objective')}
              value={filters.objective}
              onChange={(objective) => update({ objective })}
              options={(data?.meta.objectives ?? []).map((o) => ({
                value: o.objective,
                label: o.label,
              }))}
              any={t('campaignControl.any')}
            />
            <FilterSelect
              label={t('campaignControl.performance')}
              value={filters.health}
              onChange={(health) => update({ health: health as ControlFilters['health'] })}
              options={(data?.meta.health ?? []).map((h) => ({
                value: h.health,
                label: t(`health.${HEALTH_TO_STATE[h.health]}`, { defaultValue: h.label }),
              }))}
              any={t('campaignControl.any')}
            />
            <FilterSelect
              label={t('campaignControl.budgetState')}
              value={filters.budget}
              onChange={(budget) => update({ budget: budget as ControlFilters['budget'] })}
              options={[
                { value: 'high', label: t('campaignControl.budgetHigh') },
                { value: 'low', label: t('campaignControl.budgetLow') },
              ]}
              any={t('campaignControl.any')}
            />
            <FilterSelect
              label={t('campaignControl.endsWithin')}
              value={filters.endsWithin === null ? null : String(filters.endsWithin)}
              onChange={(days) => update({ endsWithin: days === null ? null : Number(days) })}
              options={ENDS_WITHIN.map((days) => ({
                value: String(days),
                label: t('campaignControl.inDays', { count: days }),
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
            <FilterSelect
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
              <CampaignCard
                row={row}
                selected={chosen.has(row.id)}
                onToggle={() => toggle(row.id)}
              />
            </li>
          ))}
        </ul>
      ) : (
        <CampaignTable
          rows={data.data}
          chosen={chosen}
          onToggle={toggle}
          onToggleAll={(all) =>
            setChosen(all ? new Set(data.data.map((row) => row.id)) : new Set())
          }
          sort={filters.sort}
          descending={filters.descending}
          onSort={(sort) =>
            update(
              sort === filters.sort
                ? { descending: !filters.descending }
                : { sort, descending: false },
            )
          }
          providerLabels={providerLabels}
          settings={tableSettings}
        />
      )}

      {chosen.size === 0 ? null : (
        <BulkBar ids={[...chosen]} onClear={() => setChosen(new Set())} />
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
