import { LayoutGrid, Rows3 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { useClientsQuery } from '@/features/clients';
import { CreativeCard, CreativeMediaTile } from '@/features/creatives';
import type { CreativeRow } from '@/features/creatives';
import { ProviderLogo, useProvidersQuery } from '@/features/integrations';
import { SavedViewsMenu } from '@/features/surface-views';
import { formatMetric, MISSING } from '@/shared/metrics';
import type { MetricValue } from '@/shared/metrics';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EmptyState } from '@/shared/ui/empty-state';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { FilterSelect } from '@/shared/ui/filter-select';
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
import { DEFAULT_FILTERS, PERIODS, fromParams, toParams, type AdFilters } from './filters';
import { PAGE_SIZE, useAds } from './useAds';

const STATUSES = ['active', 'paused', 'in_review', 'rejected', 'completed'] as const;
const VERDICTS = ['top', 'rising', 'stable', 'fatiguing', 'weak'] as const;

/**
 * Ads: the level a viewer actually looks at, as a screen of its own.
 *
 * Media first — an ad is a picture before it is a row — with the table one press away for row-by-row comparison. Every
 * figure is the creative layer's, at the grain of an ad: its own rows, never its ad group's or its campaign's. A
 * picture is drawn only where the server vouches for the address, and a missing figure is a dash rather than a zero.
 */
export function AdsPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [params, setParams] = useSearchParams();
  const { context, setContext } = useUiStore();
  const sharedDays = useUiStore((state) => state.period.days);
  const fromUrl = fromParams(params);
  const filters: AdFilters = {
    ...fromUrl,
    client: fromUrl.client ?? context.client,
    project: fromUrl.project ?? context.project,
  };
  const { data, isPending, isError, isFetching } = useAds(filters, sharedDays);
  const providers = useProvidersQuery();
  const [clientTerm, setClientTerm] = useState('');
  const clients = useClientsQuery({ search: clientTerm, per_page: 8 });

  const update = (patch: Partial<AdFilters>) => {
    const resets = Object.keys(patch).some((key) => key !== 'offset' && key !== 'view');

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
    filters.adGroup !== null ||
    filters.provider !== null ||
    filters.status !== null ||
    filters.verdict !== null ||
    filters.format !== null;

  const show = (value: MetricValue | undefined): string =>
    value === undefined ? MISSING : formatMetric(value, locale, true);

  return (
    <div className="ds-page">
      <PageHeader
        title={t('ads.title')}
        actions={
          <SavedViewsMenu
            surface="ads"
            current={Object.fromEntries(toParams({ ...filters, offset: 0 }))}
            project={
              filters.project === null
                ? null
                : { id: filters.project.id, label: filters.project.label }
            }
            presets={[
              { key: 'top', label: t('ads.preset.top'), filters: { verdict: 'top' } },
              {
                key: 'fatiguing',
                label: t('ads.preset.fatiguing'),
                filters: { verdict: 'fatiguing' },
              },
              { key: 'replace', label: t('ads.preset.replace'), filters: { verdict: 'weak' } },
              { key: 'active', label: t('ads.preset.active'), filters: { status: 'active' } },
            ]}
            onApply={(saved) => setParams(new URLSearchParams(saved), { replace: true })}
          />
        }
      />

      {isError ? <Alert tone="danger">{t('ads.loadFailed')}</Alert> : null}

      <section aria-label={t('ads.summary')} className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <KpiTile
          highlight
          label={t('ads.matched')}
          value={data === undefined ? '—' : String(data.total)}
        />
        {(data?.facets.platforms ?? []).slice(0, 3).map((platform) => (
          <KpiTile key={platform.key} label={platform.label} value={String(platform.count)} />
        ))}
      </section>

      <section aria-label={t('ads.filters')} className="flex flex-col gap-3">
        <div className="bg-surface flex flex-wrap items-end gap-3 rounded-3xl p-4 shadow-xs">
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
            options={STATUSES.map((status) => ({
              value: status,
              label: t(`campaignControl.statusName.${status}`),
            }))}
            any={t('campaignControl.any')}
          />
          <FilterSelect
            label={t('ads.creativeStatus')}
            value={filters.verdict}
            onChange={(verdict) => update({ verdict })}
            options={VERDICTS.map((verdict) => ({
              value: verdict,
              label: t(`creatives.verdict.${verdict}`),
            }))}
            any={t('campaignControl.any')}
          />
          <FilterSelect
            label={t('ads.format')}
            value={filters.format}
            onChange={(format) => update({ format })}
            options={(data?.facets.formats ?? []).map((f) => ({ value: f.key, label: f.label }))}
            any={t('campaignControl.any')}
          />
          <div className="flex gap-1" role="group" aria-label={t('campaignControl.view')}>
            <Button
              variant={filters.view === 'media' ? 'secondary' : 'ghost'}
              size="icon"
              aria-pressed={filters.view === 'media'}
              aria-label={t('ads.media')}
              onClick={() => update({ view: 'media' })}
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
        {filters.campaign === null && filters.adGroup === null ? null : (
          <div className="flex flex-wrap items-center gap-2 text-sm">
            {filters.campaign === null ? null : (
              <Badge tone="neutral">
                {t('adGroups.inCampaign')} <span dir="auto">{filters.campaign.label}</span>
              </Badge>
            )}
            {filters.adGroup === null ? null : (
              <Badge tone="neutral">
                {t('ads.inAdGroup')} <span dir="auto">{filters.adGroup.label}</span>
              </Badge>
            )}
            <Button variant="ghost" onClick={() => update({ campaign: null, adGroup: null })}>
              {t('campaignControl.clear')}
            </Button>
          </div>
        )}
      </section>

      {isPending ? (
        <div
          role="status"
          aria-label={t('ads.loading')}
          className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        >
          {Array.from({ length: 8 }, (_, index) => (
            <Skeleton key={index} className="h-64" />
          ))}
        </div>
      ) : data === undefined || data.rows.length === 0 ? (
        <EmptyState
          title={t(isFiltered ? 'ads.empty.filtered' : 'ads.empty.none')}
          description={t('ads.empty.body')}
          action={
            <Button
              variant="secondary"
              onClick={() => setParams(toParams(DEFAULT_FILTERS), { replace: true })}
            >
              {t('campaignControl.reset')}
            </Button>
          }
        />
      ) : filters.view === 'media' ? (
        <ul
          aria-busy={isFetching}
          aria-label={t('ads.title')}
          className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        >
          {data.rows.map((row) => (
            <CreativeCard key={row.key} row={row} href={`/ads/${row.key}`} />
          ))}
        </ul>
      ) : (
        <DataTable label={t('ads.title')} minWidth="76rem" maxHeight="70vh">
          <TableHead sticky>
            <TableRow>
              <TableHeader>{t('ads.col.preview')}</TableHeader>
              <TableHeader>{t('ads.col.ad')}</TableHeader>
              <TableHeader>{t('campaignControl.col.platform')}</TableHeader>
              <TableHeader>{t('adGroups.col.campaign')}</TableHeader>
              <TableHeader>{t('adGroups.col.adGroup')}</TableHeader>
              <TableHeader>{t('campaignControl.col.status')}</TableHeader>
              <TableHeader align="end">{t('campaignControl.col.spend')}</TableHeader>
              <TableHeader align="end">{t('campaignControl.col.result')}</TableHeader>
              <TableHeader align="end">{t('campaignControl.col.ctr')}</TableHeader>
              <TableHeader align="end">CPA</TableHeader>
              <TableHeader align="end">ROAS</TableHeader>
              <TableHeader align="end">{t('creatives.frequency')}</TableHeader>
              <TableHeader>{t('ads.creativeStatus')}</TableHeader>
            </TableRow>
          </TableHead>
          <TableBody aria-busy={isFetching}>
            {data.rows.map((row: CreativeRow) => (
              <TableRow key={row.key} isInteractive>
                <TableCell>
                  <CreativeMediaTile
                    media={row.media}
                    format={row.format}
                    label={row.label}
                    className="w-24 rounded-lg"
                  />
                </TableCell>
                <TableCell>
                  <Link
                    to={`/ads/${row.key}`}
                    className="text-content text-sm font-medium hover:underline"
                    dir="auto"
                  >
                    {row.label}
                  </Link>
                </TableCell>
                <TableCell label={t('campaignControl.col.platform')}>
                  <span className="flex items-center gap-2">
                    <ProviderLogo provider={row.platform as never} className="size-6" />
                    <span className="text-content-secondary text-xs">{row.platform_label}</span>
                  </span>
                </TableCell>
                <TableCell label={t('adGroups.col.campaign')}>
                  {row.parents?.campaign == null ? (
                    '—'
                  ) : (
                    <Link
                      to={`/campaigns/${row.parents.campaign.id}`}
                      className="text-content-secondary hover:underline"
                      dir="auto"
                    >
                      {row.parents.campaign.name}
                    </Link>
                  )}
                </TableCell>
                <TableCell label={t('adGroups.col.adGroup')}>
                  {row.parents?.ad_group == null ? (
                    '—'
                  ) : (
                    <Link
                      to={`/ad-groups/${row.parents.ad_group.id}`}
                      className="text-content-secondary hover:underline"
                      dir="auto"
                    >
                      {row.parents.ad_group.name}
                    </Link>
                  )}
                </TableCell>
                <TableCell label={t('campaignControl.col.status')}>
                  {row.ad_status == null ? (
                    '—'
                  ) : (
                    <Badge tone="neutral">
                      {t(`campaignControl.statusName.${row.ad_status}`, {
                        defaultValue: row.ad_status,
                      })}
                    </Badge>
                  )}
                </TableCell>
                <TableCell isNumeric align="end" label={t('campaignControl.col.spend')}>
                  {show(row.metrics.values['spend'])}
                </TableCell>
                <TableCell isNumeric align="end" label={t('campaignControl.col.result')}>
                  {show(row.metrics.values['conversions'])}
                </TableCell>
                <TableCell isNumeric align="end" label={t('campaignControl.col.ctr')}>
                  {show(row.metrics.values['ctr'])}
                </TableCell>
                <TableCell isNumeric align="end" label="CPA">
                  {show(row.metrics.values['cpa'])}
                </TableCell>
                <TableCell isNumeric align="end" label="ROAS">
                  {show(row.metrics.values['roas'])}
                </TableCell>
                <TableCell isNumeric align="end" label={t('creatives.frequency')}>
                  {show(row.metrics.values['frequency'])}
                </TableCell>
                <TableCell label={t('ads.creativeStatus')}>
                  {row.verdict === null ? '—' : t(`creatives.verdict.${row.verdict}`)}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </DataTable>
      )}

      {data !== undefined && data.total > PAGE_SIZE ? (
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
              to: Math.min(filters.offset + PAGE_SIZE, data.total),
              total: data.total,
            })}
          </span>
          <Button
            variant="secondary"
            disabled={!data.has_more}
            onClick={() => update({ offset: filters.offset + PAGE_SIZE })}
          >
            {t('campaignControl.next')}
          </Button>
        </nav>
      ) : null}
    </div>
  );
}
