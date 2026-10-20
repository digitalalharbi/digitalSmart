import { useMemo, useState } from 'react';
import { SlidersHorizontal, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/shared/ui/button';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { ContextDrawer } from '@/shared/ui/context-drawer';
import { Input } from '@/shared/ui/input';
import {
  hasOptions,
  isFiltered,
  NO_FILTERS,
  type SharedFilterInfo,
  type SharedFilterState,
} from './sharedFilters';

/** How many campaigns are listed before the search box is the way to find the rest. */
const SHOWN = 12;

/**
 * Narrowing a shared report, kept simple enough that it never feels like an admin panel.
 *
 * One compact row under the report's header: a Filters button, a few chips for what is on, and a way to clear them. The
 * controls themselves open in a sheet — a full-height sheet on a phone — so the page itself stays a report.
 *
 * It offers only what the server says this link can be narrowed by, and nothing for a dimension with a single value.
 * Every choice here is a *request to see less*: the server looks it up inside the link's own scope and refuses what is
 * not there, so nothing on this screen widens anything.
 */
export function SharedFilterBar({
  info,
  filters,
  onChange,
}: {
  info: SharedFilterInfo | null | undefined;
  filters: SharedFilterState;
  onChange: (next: SharedFilterState) => void;
}) {
  const { t, i18n } = useTranslation();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');

  const options = info?.available ?? null;
  const day = (iso: string): string =>
    new Intl.DateTimeFormat(i18n.language, {
      month: 'short',
      day: 'numeric',
      timeZone: 'UTC',
    }).format(new Date(`${iso}T00:00:00Z`));

  const campaigns = useMemo(() => {
    const all = options?.campaigns ?? [];
    const needle = search.trim().toLowerCase();

    return needle === ''
      ? all.slice(0, SHOWN)
      : all.filter((campaign) => campaign.label.toLowerCase().includes(needle)).slice(0, SHOWN);
  }, [options, search]);

  if (!hasOptions(info) || options === null) {
    return null;
  }

  const toggle = (list: string[], key: string): string[] =>
    list.includes(key) ? list.filter((entry) => entry !== key) : [...list, key];

  const chips: { key: string; label: string; remove: () => void }[] = [
    ...filters.platforms.map((key) => ({
      key: `p-${key}`,
      label: options.platforms.find((entry) => entry.key === key)?.label ?? key,
      remove: () => onChange({ ...filters, platforms: filters.platforms.filter((p) => p !== key) }),
    })),
    ...filters.campaigns.map((key) => ({
      key: `c-${key}`,
      label: options.campaigns.find((entry) => entry.key === key)?.label ?? key,
      remove: () => onChange({ ...filters, campaigns: filters.campaigns.filter((c) => c !== key) }),
    })),
    ...(filters.store === null
      ? []
      : [
          {
            key: 'store',
            label: options.stores.find((entry) => entry.key === filters.store)?.label ?? '',
            remove: () => onChange({ ...filters, store: null }),
          },
        ]),
    ...(filters.from === null && filters.until === null
      ? []
      : [
          {
            key: 'period',
            label: `${day(filters.from ?? options.period.from)} – ${day(filters.until ?? options.period.until)}`,
            remove: () => onChange({ ...filters, from: null, until: null }),
          },
        ]),
  ];

  return (
    <div className="flex flex-col gap-2" data-testid="shared-filters">
      <div className="flex flex-wrap items-center gap-2">
        <Button type="button" variant="secondary" size="sm" onClick={() => setOpen(true)}>
          <SlidersHorizontal aria-hidden className="size-3.5" />
          {t('sharedReport.filters.open')}
          {chips.length === 0 ? null : (
            <span className="bg-accent-strong text-accent-contrast ms-1 rounded-full px-1.5 text-xs">
              {new Intl.NumberFormat(i18n.language).format(chips.length)}
            </span>
          )}
        </Button>

        {chips.map((chip) => (
          <span
            key={chip.key}
            className="bg-surface-sunken text-content inline-flex max-w-full items-center gap-1 rounded-full py-1 ps-3 pe-1 text-xs"
          >
            <span className="truncate">{chip.label}</span>
            <button
              type="button"
              onClick={chip.remove}
              aria-label={t('sharedReport.filters.remove', { name: chip.label })}
              className="hover:bg-surface grid size-5 place-items-center rounded-full"
            >
              <X aria-hidden className="size-3" />
            </button>
          </span>
        ))}

        {isFiltered(filters) ? (
          <Button type="button" variant="ghost" size="sm" onClick={() => onChange(NO_FILTERS)}>
            {t('sharedReport.filters.clear')}
          </Button>
        ) : null}
      </div>

      <ContextDrawer
        open={open}
        onOpenChange={setOpen}
        title={t('sharedReport.filters.title')}
        description={t('sharedReport.filters.hint')}
        footer={
          <div className="flex w-full items-center justify-between gap-2">
            <Button type="button" variant="ghost" onClick={() => onChange(NO_FILTERS)}>
              {t('sharedReport.filters.clear')}
            </Button>
            <Button type="button" onClick={() => setOpen(false)}>
              {t('sharedReport.filters.done')}
            </Button>
          </div>
        }
      >
        <div className="flex flex-col gap-5">
          {options.platforms.length === 0 ? null : (
            <ChoiceGroup label={t('sharedReport.filters.platform')}>
              <div className="flex flex-wrap gap-2">
                {options.platforms.map((platform) => (
                  <Choice
                    key={platform.key}
                    isSelected={filters.platforms.includes(platform.key)}
                    onSelect={() =>
                      onChange({ ...filters, platforms: toggle(filters.platforms, platform.key) })
                    }
                  >
                    {platform.label}
                  </Choice>
                ))}
              </div>
            </ChoiceGroup>
          )}

          {options.campaigns.length === 0 ? null : (
            <ChoiceGroup label={t('sharedReport.filters.campaign')}>
              {options.campaigns.length > SHOWN ? (
                <Input
                  type="search"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  placeholder={t('sharedReport.filters.search')}
                  aria-label={t('sharedReport.filters.search')}
                />
              ) : null}
              <div className="flex flex-wrap gap-2">
                {campaigns.map((campaign) => (
                  <Choice
                    key={campaign.key}
                    isSelected={filters.campaigns.includes(campaign.key)}
                    onSelect={() =>
                      onChange({ ...filters, campaigns: toggle(filters.campaigns, campaign.key) })
                    }
                  >
                    {campaign.label}
                  </Choice>
                ))}
              </div>
            </ChoiceGroup>
          )}

          {options.stores.length === 0 ? null : (
            <ChoiceGroup label={t('sharedReport.filters.store')}>
              <div className="flex flex-wrap gap-2">
                {options.stores.map((store) => (
                  <Choice
                    key={store.key}
                    isSelected={filters.store === store.key}
                    onSelect={() =>
                      onChange({
                        ...filters,
                        store: filters.store === store.key ? null : store.key,
                      })
                    }
                  >
                    {store.label}
                  </Choice>
                ))}
              </div>
              <p className="text-content-tertiary text-xs">{t('sharedReport.filters.storeNote')}</p>
            </ChoiceGroup>
          )}

          {options.period.from < options.period.until ? (
            <ChoiceGroup label={t('sharedReport.filters.period')}>
              <div className="grid grid-cols-2 gap-2">
                <label className="flex min-w-0 flex-col gap-1 text-xs">
                  <span className="text-content-secondary">{t('sharedReport.filters.from')}</span>
                  <Input
                    type="date"
                    value={filters.from ?? options.period.from}
                    min={options.period.from}
                    max={filters.until ?? options.period.until}
                    onChange={(event) =>
                      onChange({
                        ...filters,
                        from: event.target.value === '' ? null : event.target.value,
                      })
                    }
                  />
                </label>
                <label className="flex min-w-0 flex-col gap-1 text-xs">
                  <span className="text-content-secondary">{t('sharedReport.filters.until')}</span>
                  <Input
                    type="date"
                    value={filters.until ?? options.period.until}
                    min={filters.from ?? options.period.from}
                    max={options.period.until}
                    onChange={(event) =>
                      onChange({
                        ...filters,
                        until: event.target.value === '' ? null : event.target.value,
                      })
                    }
                  />
                </label>
              </div>
            </ChoiceGroup>
          ) : null}
        </div>
      </ContextDrawer>
    </div>
  );
}
