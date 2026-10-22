import { ArrowDown, ArrowUp, ArrowUpDown, Columns3, Rows3 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { ProviderLogo } from '@/features/integrations';
import { formatPercent, localeTag } from '@/shared/lib/format';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { HealthPill } from '@/shared/ui/health';
import { Sparkline } from '@/shared/ui/sparkline';
import {
  DataTable,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/shared/ui/table';
import { cn } from '@/shared/lib/cn';
import { BudgetBar } from './CampaignParts';
import { COLUMN_IDS, type ColumnId, type TableSettings } from './columns';
import { objectiveName, resultName, statusName } from './labels';
import { money, resultText } from './text';
import { HEALTH_TO_STATE, type CampaignRow, type SortKey } from './types';

/** The columns the server can order by — the rest are read, not sorted, because the page is one slice of a longer list. */
const SORT_OF: Partial<Record<ColumnId | 'campaign', SortKey>> = {
  campaign: 'name',
  health: 'health',
  spend: 'spend',
  cpa: 'cost',
  roas: 'roas',
};

const NUMERIC: ReadonlySet<ColumnId> = new Set([
  'budget',
  'spend',
  'results',
  'cpa',
  'revenue',
  'roas',
  'ctr',
]);

/** A date the platform gave (`YYYY-MM-DD`), read as that calendar day rather than shifted by the reader's zone. */
function day(value: string | null, locale: string): string {
  if (value === null) {
    return '—';
  }

  return new Intl.DateTimeFormat(localeTag(locale), {
    dateStyle: 'medium',
    timeZone: 'UTC',
  }).format(new Date(`${value}T00:00:00Z`));
}

/**
 * The campaign table: the working view of the Ads Manager.
 *
 * Dense on purpose — an operator compares rows — but each figure is the same one the cards show, from the same
 * rows, and a figure nobody reported is a dash rather than a zero. The head stays in view while the list scrolls,
 * the columns are the reader's choice, and on a phone every row becomes a card (the shared table does that), so
 * nothing here squeezes a desktop layout onto 375 pixels.
 */
export function CampaignTable({
  rows,
  chosen,
  onToggle,
  onToggleAll,
  sort,
  descending,
  onSort,
  providerLabels,
  settings,
}: {
  rows: CampaignRow[];
  chosen: ReadonlySet<string>;
  onToggle: (id: string) => void;
  onToggleAll: (all: boolean) => void;
  sort: SortKey;
  descending: boolean;
  onSort: (sort: SortKey) => void;
  providerLabels: ReadonlyMap<string, string>;
  settings: TableSettings;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const shown = (id: ColumnId) => !settings.hidden.includes(id);
  const pad = settings.dense ? 'py-1.5' : '';

  const heading = (id: ColumnId | 'campaign', text: string) => {
    const key = SORT_OF[id];
    const align = id !== 'campaign' && NUMERIC.has(id) ? 'end' : 'start';

    if (key === undefined) {
      return (
        <TableHeader key={id} align={align}>
          {text}
        </TableHeader>
      );
    }

    const active = sort === key;
    const Icon = !active ? ArrowUpDown : descending ? ArrowDown : ArrowUp;

    return (
      <TableHeader
        key={id}
        align={align}
        aria-sort={!active ? 'none' : descending ? 'descending' : 'ascending'}
      >
        <button
          type="button"
          onClick={() => onSort(key)}
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
    <DataTable label={t('campaignControl.title')} minWidth="72rem" maxHeight="70vh">
      <TableHead sticky>
        <TableRow>
          <TableHeader>
            <input
              type="checkbox"
              aria-label={t('bulk.selectPage')}
              className="accent-action size-4"
              checked={rows.length > 0 && rows.every((row) => chosen.has(row.id))}
              onChange={(event) => onToggleAll(event.target.checked)}
            />
          </TableHeader>
          {shown('platform') ? heading('platform', t('campaignControl.col.platform')) : null}
          {heading('campaign', t('campaignControl.col.campaign'))}
          {shown('client') ? heading('client', t('campaignControl.col.client')) : null}
          {shown('project') ? heading('project', t('campaignControl.col.project')) : null}
          {shown('status') ? heading('status', t('campaignControl.col.status')) : null}
          {shown('objective') ? heading('objective', t('campaignControl.col.objective')) : null}
          {shown('budget') ? heading('budget', t('campaignControl.col.budget')) : null}
          {shown('spend') ? heading('spend', t('campaignControl.col.spend')) : null}
          {shown('results') ? heading('results', t('campaignControl.col.result')) : null}
          {shown('cpa') ? heading('cpa', t('campaignControl.col.costPerResult')) : null}
          {shown('revenue') ? heading('revenue', t('campaignControl.col.revenue')) : null}
          {shown('roas') ? heading('roas', 'ROAS') : null}
          {shown('ctr') ? heading('ctr', t('campaignControl.col.ctr')) : null}
          {shown('start') ? heading('start', t('campaignControl.col.start')) : null}
          {shown('end') ? heading('end', t('campaignControl.col.end')) : null}
          {shown('health') ? heading('health', t('campaignControl.col.health')) : null}
          {shown('trend') ? heading('trend', t('campaignControl.col.trend')) : null}
          {shown('sync') ? heading('sync', t('campaignControl.col.sync')) : null}
        </TableRow>
      </TableHead>
      <TableBody>
        {rows.map((row) => (
          <TableRow key={row.id} isInteractive>
            <TableCell className={pad}>
              <input
                type="checkbox"
                aria-label={t('bulk.select', { name: row.name })}
                className="accent-action size-4"
                checked={chosen.has(row.id)}
                onChange={() => onToggle(row.id)}
              />
            </TableCell>
            {shown('platform') ? (
              <TableCell className={pad} label={t('campaignControl.col.platform')}>
                <span className="flex items-center gap-2">
                  <ProviderLogo provider={row.provider as never} className="size-6" />
                  <span className="text-content-secondary text-xs">
                    {providerLabels.get(row.provider) ?? row.provider}
                  </span>
                </span>
              </TableCell>
            ) : null}
            <TableCell className={pad}>
              <span className="flex min-w-0 flex-col">
                <Link
                  to={`/campaigns/${row.id}`}
                  className="text-content truncate text-sm font-medium hover:underline"
                >
                  {row.name}
                </Link>
                <span className="text-content-tertiary truncate text-xs">
                  {row.external_account.name}
                </span>
              </span>
            </TableCell>
            {shown('client') ? (
              <TableCell className={pad} label={t('campaignControl.col.client')}>
                {row.client_name ?? '—'}
              </TableCell>
            ) : null}
            {shown('project') ? (
              <TableCell className={pad} label={t('campaignControl.col.project')}>
                {row.project_name ?? '—'}
              </TableCell>
            ) : null}
            {shown('status') ? (
              <TableCell className={pad} label={t('campaignControl.col.status')}>
                <Badge tone="neutral">{statusName(t, row)}</Badge>
              </TableCell>
            ) : null}
            {shown('objective') ? (
              <TableCell className={pad} label={t('campaignControl.col.objective')}>
                {objectiveName(t, row)}
              </TableCell>
            ) : null}
            {shown('budget') ? (
              <TableCell
                className={pad}
                isNumeric
                align="end"
                label={t('campaignControl.col.budget')}
              >
                <span className="flex min-w-28 flex-col items-end gap-1">
                  <span>{money(row.budget_minor, row.currency, locale)}</span>
                  <BudgetBar row={row} />
                </span>
              </TableCell>
            ) : null}
            {shown('spend') ? (
              <TableCell
                className={pad}
                isNumeric
                align="end"
                label={t('campaignControl.col.spend')}
              >
                {money(row.spend_minor, row.currency, locale)}
              </TableCell>
            ) : null}
            {shown('results') ? (
              <TableCell
                className={pad}
                isNumeric
                align="end"
                label={t('campaignControl.col.result')}
              >
                <span className="flex flex-col items-end">
                  <span>{resultText(row, locale)}</span>
                  <span className="text-content-tertiary text-xs">{resultName(t, row)}</span>
                </span>
              </TableCell>
            ) : null}
            {shown('cpa') ? (
              <TableCell
                className={pad}
                isNumeric
                align="end"
                label={t('campaignControl.col.costPerResult')}
              >
                {money(row.cost_per_result_minor, row.currency, locale)}
              </TableCell>
            ) : null}
            {shown('revenue') ? (
              <TableCell
                className={pad}
                isNumeric
                align="end"
                label={t('campaignControl.col.revenue')}
              >
                {money(row.revenue_minor, row.currency, locale)}
              </TableCell>
            ) : null}
            {shown('roas') ? (
              <TableCell className={pad} isNumeric align="end" label="ROAS">
                {row.roas === null ? '—' : `${row.roas.toFixed(2)}×`}
              </TableCell>
            ) : null}
            {shown('ctr') ? (
              <TableCell className={pad} isNumeric align="end" label={t('campaignControl.col.ctr')}>
                {row.ctr === null ? '—' : formatPercent(row.ctr, locale, 2)}
              </TableCell>
            ) : null}
            {shown('start') ? (
              <TableCell className={pad} label={t('campaignControl.col.start')}>
                {day(row.starts_on, locale)}
              </TableCell>
            ) : null}
            {shown('end') ? (
              <TableCell className={pad} label={t('campaignControl.col.end')}>
                {day(row.ends_on, locale)}
              </TableCell>
            ) : null}
            {shown('health') ? (
              <TableCell className={pad} label={t('campaignControl.col.health')}>
                <HealthPill state={HEALTH_TO_STATE[row.health]} />
              </TableCell>
            ) : null}
            {shown('trend') ? (
              <TableCell className={pad} label={t('campaignControl.col.trend')}>
                <Sparkline
                  values={row.trend}
                  label={t('campaignControl.trendLabel', { name: row.name })}
                />
              </TableCell>
            ) : null}
            {shown('sync') ? (
              <TableCell className={pad} label={t('campaignControl.col.sync')}>
                {row.last_synced_at === null ? '—' : formatRelativeTime(row.last_synced_at, locale)}
              </TableCell>
            ) : null}
          </TableRow>
        ))}
      </TableBody>
    </DataTable>
  );
}

/** Which columns show, and how tight the rows are. Remembered by the page; nothing depends on it. */
export function TableSettingsMenu({
  settings,
  onChange,
}: {
  settings: TableSettings;
  onChange: (next: TableSettings) => void;
}) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);

  return (
    <div className="relative">
      <Button
        variant="secondary"
        aria-expanded={open}
        aria-controls="campaign-columns"
        onClick={() => setOpen((value) => !value)}
      >
        <Columns3 aria-hidden />
        {t('campaignControl.columns.title')}
      </Button>
      {open ? (
        <div
          id="campaign-columns"
          role="group"
          aria-label={t('campaignControl.columns.title')}
          className="bg-surface border-line-subtle absolute end-0 z-30 mt-2 flex w-64 max-w-[calc(100vw-2rem)] flex-col gap-2 rounded-2xl border p-4 shadow-lg"
        >
          <ul className="flex max-h-72 flex-col gap-1.5 overflow-y-auto">
            {COLUMN_IDS.map((id) => (
              <li key={id}>
                <label className="flex items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={!settings.hidden.includes(id)}
                    onChange={(event) =>
                      onChange({
                        ...settings,
                        hidden: event.target.checked
                          ? settings.hidden.filter((hidden) => hidden !== id)
                          : [...settings.hidden, id],
                      })
                    }
                  />
                  {t(`campaignControl.columns.${id}`)}
                </label>
              </li>
            ))}
          </ul>
          <label className="border-line-subtle flex items-center gap-2 border-t pt-2 text-sm">
            <input
              type="checkbox"
              checked={settings.dense}
              onChange={(event) => onChange({ ...settings, dense: event.target.checked })}
            />
            <Rows3 aria-hidden className="size-4" />
            {t('campaignControl.columns.dense')}
          </label>
        </div>
      ) : null}
    </div>
  );
}
