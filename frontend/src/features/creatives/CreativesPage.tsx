import { X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { useClientsQuery } from '@/features/clients';
import { useProjectsQuery } from '@/features/projects';
import { localeTag } from '@/shared/lib/format';
import { useUiStore } from '@/shared/store/ui-store';
import { Alert } from '@/shared/ui/alert';
import { Choice } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { DonutChart } from '@/shared/ui/viz';
import { CreativeCard } from './CreativeCard';
import { CreativeComparison } from './CreativeComparison';
import { useCreatives } from './useCreatives';
import type { CreativeFacet, CreativeRow, CreativeVerdict } from './types';

const VIEWS = ['top', 'rising', 'fatiguing', 'weak'] as const satisfies readonly CreativeVerdict[];
type View = (typeof VIEWS)[number];
const COMPARE_LIMIT = 4;
const PRESETS = { 7: '7d', 30: '30d', 90: '90d' } as const;
const SERIES = ['var(--ds-chart-1)', 'var(--ds-chart-2)', 'var(--ds-chart-3)', 'var(--ds-chart-4)'];

/**
 * How creatives performed, across the workspace.
 *
 * Four views of the same ads — the strongest, the rising, the wearing out, the ones to replace — each judged by the
 * server's deterministic rules with the evidence beside the verdict; a mix by format and by platform; and a comparison
 * of two to four. Pictures come first. Where a creative has no preview that can honestly be shown, the tile says so
 * rather than drawing something in its place.
 *
 * Not here: organic content (no reach or engagement is synced for it) and anything per audience or placement.
 */
export function CreativesPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const period = useUiStore((state) => state.period);
  const [params, setParams] = useSearchParams();
  const campaignId = params.get('campaign');
  const [view, setView] = useState<View>('top');
  const [chosen, setChosen] = useState<string[]>([]);
  const [client, setClient] = useState<{ id: string; label: string } | null>(null);
  const [project, setProject] = useState<{ id: string; label: string } | null>(null);
  const [clientTerm, setClientTerm] = useState('');
  const [projectTerm, setProjectTerm] = useState('');
  const [platform, setPlatform] = useState<string | null>(null);
  const [format, setFormat] = useState<string | null>(null);
  const clients = useClientsQuery({ search: clientTerm, per_page: 8 });
  const projects = useProjectsQuery({ search: projectTerm, per_page: 8 });

  const query = useMemo(
    () => ({
      preset: PRESETS[period.days],
      limit: 100,
      ...(client === null ? {} : { client_id: client.id }),
      ...(project === null ? {} : { project_ids: [project.id] }),
      ...(campaignId === null ? {} : { campaign_ids: [campaignId] }),
      ...(platform === null ? {} : { providers: [platform] }),
      ...(format === null ? {} : { format }),
    }),
    [period.days, client, project, campaignId, platform, format],
  );
  const creatives = useCreatives(query);
  const rows = useMemo(() => creatives.data?.rows ?? [], [creatives.data]);

  const byView = useMemo(
    () =>
      Object.fromEntries(
        VIEWS.map((entry) => [entry, rows.filter((row) => row.verdict === entry)]),
      ) as Record<View, CreativeRow[]>,
    [rows],
  );
  const compared = chosen
    .map((key) => rows.find((row) => row.key === key))
    .filter((row): row is CreativeRow => row !== undefined);
  const toggle = (key: string): void =>
    setChosen((current) =>
      current.includes(key)
        ? current.filter((entry) => entry !== key)
        : current.length >= COMPARE_LIMIT
          ? current
          : [...current, key],
    );
  const number = new Intl.NumberFormat(localeTag(locale));
  const facets = creatives.data?.facets;
  const hasFilters = client !== null || project !== null || platform !== null || format !== null;

  return (
    <div className="flex flex-col gap-5">
      <PageHeader title={t('creatives.title')} description={t('creatives.description')} />

      <div
        className="flex flex-wrap items-end gap-3"
        role="search"
        aria-label={t('creatives.filters')}
      >
        <EntityPicker
          label={t('creatives.client')}
          clearLabel={t('creatives.clear')}
          value={client}
          onChange={(option) => {
            setClient(option === null ? null : { id: option.id, label: option.label });
            setChosen([]);
          }}
          onSearch={setClientTerm}
          options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
          isLoading={clients.isFetching}
        />
        <EntityPicker
          label={t('creatives.project')}
          clearLabel={t('creatives.clear')}
          value={project}
          onChange={(option) => {
            setProject(option === null ? null : { id: option.id, label: option.label });
            setChosen([]);
          }}
          onSearch={setProjectTerm}
          options={(projects.data?.data ?? []).map((p) => ({ id: p.id, label: p.name }))}
          isLoading={projects.isFetching}
        />
        <FacetSelect
          label={t('creatives.platform')}
          any={t('creatives.any')}
          value={platform}
          onChange={(value) => {
            setPlatform(value);
            setChosen([]);
          }}
          options={facets?.platforms ?? []}
        />
        <FacetSelect
          label={t('creatives.format')}
          any={t('creatives.any')}
          value={format}
          onChange={(value) => {
            setFormat(value);
            setChosen([]);
          }}
          options={facets?.formats ?? []}
        />
        {campaignId === null ? null : (
          <button
            type="button"
            className="bg-surface-sunken text-content inline-flex h-10 items-center gap-2 rounded-full px-4 text-sm"
            onClick={() => {
              params.delete('campaign');
              setParams(params, { replace: true });
            }}
          >
            {t('creatives.oneCampaign')}
            <X aria-hidden className="size-3.5" />
            <span className="sr-only">{t('creatives.clear')}</span>
          </button>
        )}
        <p className="text-content-tertiary self-center text-xs">
          {t('creatives.lastDays', { count: period.days })}
          {hasFilters || campaignId !== null ? ` · ${t('creatives.filtered')}` : ''}
        </p>
      </div>

      {creatives.isPending ? (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">
          {[0, 1, 2].map((index) => (
            <Skeleton key={index} className="aspect-[4/3] rounded-2xl" />
          ))}
        </div>
      ) : creatives.isError ? (
        <Alert tone="danger">{t('creatives.error')}</Alert>
      ) : rows.length === 0 ? (
        <EmptyState title={t('creatives.emptyTitle')} description={t('creatives.empty')} />
      ) : (
        <>
          <div className="flex flex-wrap gap-2" role="group" aria-label={t('creatives.views')}>
            {VIEWS.map((entry) => (
              <Choice key={entry} isSelected={view === entry} onSelect={() => setView(entry)}>
                {t(`creatives.view.${entry}`)} · {number.format(byView[entry].length)}
              </Choice>
            ))}
          </div>

          {creatives.data?.has_more === true ? (
            <p className="text-content-tertiary text-xs">
              {t('creatives.largest', {
                shown: number.format(rows.length),
                total: number.format(creatives.data.total),
              })}
            </p>
          ) : null}

          {byView[view].length === 0 ? (
            <p className="text-content-tertiary text-sm">{t(`creatives.none.${view}`)}</p>
          ) : (
            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {byView[view].map((row) => (
                <CreativeCard
                  key={row.key}
                  row={row}
                  chosen={chosen.includes(row.key)}
                  canChoose={chosen.length < COMPARE_LIMIT}
                  onToggle={() => toggle(row.key)}
                />
              ))}
            </ul>
          )}

          {compared.length >= 2 ? (
            <CreativeComparison rows={compared} />
          ) : chosen.length === 1 ? (
            <p className="text-content-tertiary text-xs">{t('creatives.pickOne')}</p>
          ) : null}

          <div className="grid gap-4 md:grid-cols-2">
            <MixDonut title={t('creatives.formatMix')} rows={rows} by="format" locale={locale} />
            <MixDonut
              title={t('creatives.platformMix')}
              rows={rows}
              by="platform"
              locale={locale}
            />
          </div>
        </>
      )}
    </div>
  );
}

function FacetSelect({
  label,
  any,
  value,
  onChange,
  options,
}: {
  label: string;
  any: string;
  value: string | null;
  onChange: (value: string | null) => void;
  options: CreativeFacet[];
}) {
  const id = `creatives-${label}`;

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
        <option value="">{any}</option>
        {options.map((option) => (
          <option key={option.key} value={option.key}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  );
}

/**
 * Where the money went, by format or by platform — drawn from the creatives on screen.
 *
 * Money in two currencies has no share, so the chart is offered only when the shown creatives agree on one; otherwise
 * it falls back to how many creatives there are of each, which needs no conversion and says so in its title.
 */
function MixDonut({
  title,
  rows,
  by,
  locale,
}: {
  title: string;
  rows: CreativeRow[];
  by: 'format' | 'platform';
  locale: string;
}) {
  const { t } = useTranslation();
  const currencies = new Set(rows.map((row) => row.metrics.currency));
  const bySpend = currencies.size === 1;
  const groups = new Map<string, { label: string; value: number }>();

  for (const row of rows) {
    const key = by === 'format' ? row.format : row.platform;
    const label = by === 'format' ? row.format_label : row.platform_label;
    const amount = bySpend ? (row.metrics.values['spend']?.value ?? 0) : 1;
    const entry = groups.get(key) ?? { label, value: 0 };
    entry.value += amount;
    groups.set(key, entry);
  }

  const total = [...groups.values()].reduce((sum, entry) => sum + entry.value, 0);
  const segments = [...groups.entries()]
    .filter(([, entry]) => entry.value > 0)
    .sort((a, b) => b[1].value - a[1].value)
    .map(([key, entry], index) => ({
      key,
      label: entry.label,
      value: entry.value,
      colour: SERIES[index] ?? 'var(--ds-chart-4)',
    }));

  if (segments.length < 2 || total <= 0) {
    return null;
  }

  return (
    <div className="flex min-w-0 flex-col gap-2">
      <h2 className="text-content text-sm font-medium">
        {title}
        <span className="text-content-tertiary ms-2 text-xs font-normal">
          {t(bySpend ? 'creatives.bySpend' : 'creatives.byCount')}
        </span>
      </h2>
      <DonutChart
        label={title}
        segments={segments}
        format={(value) =>
          new Intl.NumberFormat(localeTag(locale), {
            style: 'percent',
            maximumFractionDigits: 0,
          }).format(value / total)
        }
      />
    </div>
  );
}
