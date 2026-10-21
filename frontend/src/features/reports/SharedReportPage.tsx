import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useRef } from 'react';
import { Languages, Moon, Sun } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { useLocation, useParams, useSearchParams } from 'react-router';
import { queryKeys } from '@/shared/api/query-keys';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { InfoHint } from '@/shared/ui/info-hint';
import { Skeleton } from '@/shared/ui/skeleton';
import { resolveTheme, useUiStore } from '@/shared/store/ui-store';
import type { Locale } from '@/shared/i18n';
import { ReportView } from './ReportView';
import { SharedFilterBar } from './SharedFilterBar';
import { fetchSharedReport, isOutOfScope } from './sharedApi';
import {
  filterKey,
  isFiltered,
  parseFilters,
  toSearch,
  type SharedFilterState,
} from './sharedFilters';

/**
 * A report read through a shared link, by somebody with no account.
 *
 * The page the agency's link actually opens, and the only screen in this product rendered without a session. What
 * it does **not** do is as deliberate as what it does.
 *
 * **It is outside the shell.** No sidebar, no bell, no account menu, no navigation of any kind — there is nowhere
 * else to go from here, and a chrome full of controls that lead to a sign-in screen would invite a visitor to try
 * them.
 *
 * **It never says "not found".** A dead link gets the reason it is dead and an instruction to ask for a new one.
 * The server answers 200 with a state for exactly this reason; a 404 on a link a client was sent reads as a broken
 * site, and they ask for the report again instead of learning the link lapsed.
 *
 * **The state is rendered in the reader's language, not the server's.** The server's sentence is English and is
 * kept only as a fallback for a state this build does not recognise. A client who reads Arabic should not be told
 * in English why their link stopped working — so the language and theme controls are here too, which are the only
 * two controls on the page.
 *
 * **There is no export.** The figures are the whole document; a download route that accepted a bare token would be
 * a second unauthenticated read to get right, for a convenience the sender can provide from inside the workspace.
 */
export function SharedReportPage() {
  const { t } = useTranslation();
  const { token } = useParams<{ token: string }>();
  const { theme, locale, setTheme, setLocale } = useUiStore();
  const resolved = resolveTheme(theme);
  const nextLocale: Locale = locale === 'en' ? 'ar' : 'en';

  const [params, setParams] = useSearchParams();
  const filters = useMemo(() => parseFilters(params), [params]);
  /* Set by the redirect below, so the sentence survives it without a piece of state set from an effect. */
  const refused = (useLocation().state as { refused?: boolean } | null)?.refused === true;
  /*
   * Whether this visit has already been counted. The first read of the page is the view; changing a platform, a
   * campaign or the dates is the same visit, and the server does not count it again.
   */
  const opened = useRef(false);

  const query = useQuery({
    /*
     * The token and the normalised filters, and nothing else: two reports never share an entry, and two visitors'
     * narrowings of one report are the same entry only when they are the same narrowing.
     */
    queryKey: queryKeys.reports.shared(token ?? '', filterKey(filters)),
    queryFn: async () => {
      const answer = await fetchSharedReport(token ?? '', filters, opened.current);
      opened.current = true;

      return answer;
    },
    enabled: token !== undefined && token !== '',
    /*
     * One attempt. A retry here re-records the view, and a link's open count is a figure an agency reads as "the
     * client looked at it" — inflating it to paper over a flaky connection is the kind of small lie this product
     * does not tell.
     */
    retry: false,
    staleTime: 60_000,
    /* Keep the report on screen while a narrower one loads, so a filter change does not flash a skeleton. */
    placeholderData: keepPreviousData,
  });

  const setFilters = (next: SharedFilterState): void => {
    setParams(toSearch(next), { replace: true });
  };

  /*
   * A selection the server says is not part of this report — a stale address, a hand-edited one — is dropped and the
   * whole report shown, with a sentence saying so. The address never widens anything; at worst it asks for less than
   * the report holds and is told no.
   */
  const outOfScope = query.isError && isOutOfScope(query.error);

  useEffect(() => {
    if (outOfScope) {
      setParams(new URLSearchParams(), { replace: true, state: { refused: true } });
    }
  }, [outOfScope, setParams]);

  const result = query.data;

  return (
    <div className="bg-canvas min-h-dvh">
      <header className="border-line-subtle flex h-14 items-center gap-3 border-b px-4">
        <span
          aria-hidden="true"
          className="bg-accent-strong text-accent-contrast grid size-8 place-items-center rounded-lg text-xs font-bold"
        >
          dS
        </span>
        <span className="text-content-secondary text-sm">{t('sharedReport.chrome')}</span>

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
        {query.isPending || outOfScope ? (
          <Skeleton className="h-96 w-full" />
        ) : query.isError ? (
          /*
           * The transport failed, which is not the same as the link being dead — and saying the link expired when
           * the network dropped would have the visitor asking for a replacement they do not need.
           */
          <Alert tone="danger" className="flex flex-col items-start gap-3">
            <span>{t('sharedReport.unreachable')}</span>
            <Button variant="secondary" size="sm" onClick={() => void query.refetch()}>
              {t('actions.retry')}
            </Button>
          </Alert>
        ) : result === undefined ? null : result.state !== 'active' || result.report === null ? (
          <section
            aria-label={t('sharedReport.unavailable')}
            className="bg-surface border-line-subtle mx-auto flex max-w-md flex-col items-center gap-3 rounded-2xl border p-8 text-center"
          >
            <h1 className="text-content text-lg font-semibold">{t('sharedReport.unavailable')}</h1>
            <p className="text-content-secondary text-sm">
              {result.state === 'expired'
                ? t('sharedReport.expired')
                : result.state === 'revoked'
                  ? t('sharedReport.revoked')
                  : /* A state this build does not know. The server's own sentence, rather than a guess. */
                    (result.message ?? t('sharedReport.revoked'))}
            </p>
          </section>
        ) : (
          <>
            {result.recipient_label === null ? null : (
              /* So a reader can tell at a glance that the link they were sent is the one they opened. */
              <p className="text-content-tertiary text-sm">
                {t('sharedReport.preparedFor', { recipient: result.recipient_label })}
              </p>
            )}

            {refused ? <Alert tone="info">{t('sharedReport.filters.refused')}</Alert> : null}

            <ReportView
              report={result.report}
              isFiltered={isFiltered(filters)}
              presentable
              /* A link that ends in ?present=1 opens as a talk, for a meeting that starts from the link. */
              startPresenting={params.get('present') === '1'}
              filterBar={
                <SharedFilterBar info={result.filters} filters={filters} onChange={setFilters} />
              }
            />

            <p className="text-content-tertiary border-line-subtle flex items-center justify-center gap-1 border-t pt-4 text-xs">
              {t('sharedReport.poweredBy')}
              <InfoHint text={t('sharedReport.footer')} />
            </p>
          </>
        )}
      </main>
    </div>
  );
}
