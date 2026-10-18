import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Eye, Languages, Moon, Sun } from 'lucide-react';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { resolveTheme, useUiStore } from '@/shared/store/ui-store';
import type { Locale } from '@/shared/i18n';
import { PortalPerformancePanel } from './PortalPerformancePanel';
import { PortalReportsPanel } from './PortalReportsPanel';
import { PortalRevenuePanel } from './PortalRevenuePanel';
import { PortalTrackingPanel } from './PortalTrackingPanel';
import { PortalRequestsPanel } from './PortalRequestsPanel';
import { PortalUpdates } from './PortalUpdates';
import { useMyPortalsQuery } from './usePortal';
import type { PortalEntry, PortalModuleValue } from './types';

type Tab = PortalModuleValue | 'requests';

/**
 * The client's own portal.
 *
 * Its own shell rather than the agency's: no sidebar of agency surfaces, no notification bell, no command
 * palette. The navigation is **built from what this reader may open**, which is why there is nothing on it to be
 * refused — a menu item that answered 404 would tell a client something about them exists and is being kept from
 * them, and that is a sentence about the agency's intentions rather than a feature.
 *
 * A reader assigned to more than one client's projects gets a chooser rather than one picked for them. It is rare
 * and it is not something to guess at.
 */
export function MyPortalPage() {
  const { t } = useTranslation();
  const { theme, locale, setTheme, setLocale } = useUiStore();
  const resolved = resolveTheme(theme);
  const nextLocale: Locale = locale === 'en' ? 'ar' : 'en';

  const portals = useMyPortalsQuery();
  const entries = portals.data ?? [];

  const [clientId, setClientId] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab | null>(null);

  const entry: PortalEntry | undefined =
    entries.find((candidate) => candidate.client_id === clientId) ?? entries[0];

  const tabs: Tab[] =
    entry === undefined
      ? []
      : [...entry.modules.map((module) => module.module), 'requests' as const];

  const active: Tab | undefined = tab !== null && tabs.includes(tab) ? tab : tabs[0];

  return (
    <div className="bg-canvas min-h-dvh">
      <header className="border-line-subtle flex h-14 items-center gap-3 border-b px-4">
        <span
          aria-hidden="true"
          className="bg-accent-strong text-accent-contrast grid size-8 place-items-center rounded-lg text-xs font-bold"
        >
          dS
        </span>
        {/* The page's first-level heading: the client's own name, which is what this page is about. */}
        <h1 className="text-content text-sm font-medium" dir="auto">
          {entry?.client_name ?? t('myPortal.title')}
        </h1>

        <div className="ms-auto flex items-center gap-1">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => setLocale(nextLocale)}
            aria-label={t('actions.toggleLanguage')}
            title={t('actions.toggleLanguage')}
          >
            <Languages />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            onClick={() => setTheme(resolved === 'dark' ? 'light' : 'dark')}
            aria-label={t('actions.toggleTheme')}
            title={t('actions.toggleTheme')}
          >
            {resolved === 'dark' ? <Sun /> : <Moon />}
          </Button>
        </div>
      </header>

      <main className="mx-auto flex w-full max-w-5xl flex-col gap-5 px-4 py-8">
        {portals.isPending ? (
          <Skeleton className="h-64 w-full" />
        ) : portals.isError ? (
          <Alert tone="danger">{t('myPortal.loadFailed')}</Alert>
        ) : entry === undefined ? (
          <EmptyState
            title={t('myPortal.noPortal.title')}
            description={t('myPortal.noPortal.body')}
          />
        ) : (
          <>
            {/* So an agency member reading this cannot mistake it for their own view of the product. */}
            {!entry.is_preview ? null : (
              <Alert tone="info" className="flex items-start gap-2">
                <Eye aria-hidden className="mt-0.5 size-4 shrink-0" />
                <span>{t('myPortal.preview')}</span>
              </Alert>
            )}

            {entries.length < 2 ? null : (
              <ChoiceGroup label={t('myPortal.client')}>
                <div className="flex flex-wrap gap-2">
                  {entries.map((candidate) => (
                    <Choice
                      key={candidate.client_id}
                      isSelected={entry.client_id === candidate.client_id}
                      onSelect={() => {
                        setClientId(candidate.client_id);
                        setTab(null);
                      }}
                    >
                      {candidate.client_name}
                    </Choice>
                  ))}
                </div>
              </ChoiceGroup>
            )}

            {entry.message === null ? null : (
              /*
               * The portal is not open. The sentence says which decision this was — never set up, or switched off
               * — and never the agency's reason for it.
               */
              <section
                aria-label={t('myPortal.unavailable')}
                className="bg-surface border-line-subtle mx-auto flex max-w-md flex-col items-center gap-3 rounded-2xl border p-8 text-center"
              >
                <h1 className="text-content text-lg font-semibold">{t('myPortal.unavailable')}</h1>
                <p className="text-content-secondary text-sm">{entry.message}</p>
              </section>
            )}

            {/*
              Above the tabs, because it is about whichever tab the client is not looking at. Absent entirely
              when there is nothing to say.
            */}
            {entry.message !== null ? null : <PortalUpdates />}

            {active === undefined ? null : (
              <>
                <nav aria-label={t('myPortal.title')}>
                  <div className="flex flex-wrap gap-2">
                    {tabs.map((value) => (
                      <Choice
                        key={value}
                        isSelected={active === value}
                        onSelect={() => setTab(value)}
                      >
                        {t(`myPortal.nav.${value}`)}
                      </Choice>
                    ))}
                  </div>
                </nav>

                <section aria-label={t(`myPortal.nav.${active}`)}>
                  {active === 'performance' ? (
                    <PortalPerformancePanel clientId={entry.client_id} />
                  ) : active === 'reports' ? (
                    <PortalReportsPanel clientId={entry.client_id} />
                  ) : active === 'store_revenue' ? (
                    <PortalRevenuePanel clientId={entry.client_id} />
                  ) : active === 'tracking' ? (
                    <PortalTrackingPanel clientId={entry.client_id} />
                  ) : (
                    <PortalRequestsPanel />
                  )}
                </section>
              </>
            )}
          </>
        )}
      </main>
    </div>
  );
}
