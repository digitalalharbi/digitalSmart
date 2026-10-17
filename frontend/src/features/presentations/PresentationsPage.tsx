import { useEffect, useMemo, useState } from 'react';
import { flushSync } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { ChevronLeft, ChevronRight, Download, Info, Printer } from 'lucide-react';
import { cn } from '@/shared/lib/cn';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useBudgetsQuery, type BudgetPayload } from '@/features/budgets';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { SourceBadge } from '@/shared/ui/source-badge';
import { PageHeader } from '@/shared/ui/page-header';
import { EntityPicker } from '@/shared/ui/entity-picker';
import type { MetricSourceValue } from '@/shared/metrics';
import { pptxUrl } from './api';
import { SlideView } from './SlideView';
import { useDeckQuery, usePresentationVocabularyQuery } from './usePresentations';
import type { DeckKindValue } from './types';

/**
 * Presentations.
 *
 * **The preview is the PDF.** There is no server-side converter: a reader prints this page, where the Arabic
 * shaping is the browser's own, the fonts are the product's self-hosted faces, and the charts are the engine the
 * rest of the application uses. The print stylesheet lives in `index.css` and lays one slide per page.
 *
 * PowerPoint is a separate, server-written file, because *editable* is a requirement: an agency changes a title,
 * drops a slide, adds one of their own, and a deck they cannot open is a deck they re-make by hand.
 *
 * The provisional theme is stated on the page rather than in a README. Smart Code's own presentation identity has
 * not been provided, and a placeholder presented as a brand is worse than an obvious placeholder.
 */
export function PresentationsPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const { can } = useSession();

  const canUse = can(PERMISSIONS.reportsView);

  const [kind, setKind] = useState<DeckKindValue>('monthly_report');
  /* Searched, never listed: a hundred-client agency cannot be a row of buttons, and the 101st is not a typo. */
  const [client, setClient] = useState<{ id: string; label: string } | null>(null);
  const [clientTerm, setClientTerm] = useState('');
  const clientId = client?.id ?? null;
  const [budgetId, setBudgetId] = useState<string | null>(null);
  const [position, setPosition] = useState<{ key: string; index: number }>({ key: '', index: 0 });
  /* A different deck starts at its first slide: the position remembers which deck it was chosen in. */
  const deckKey = `${kind}|${clientId ?? ''}|${budgetId ?? ''}`;
  const active = position.key === deckKey ? position.index : 0;
  const setActive = (next: number | ((current: number) => number)) =>
    setPosition({ key: deckKey, index: typeof next === 'function' ? next(active) : next });
  /*
   * Printing needs every slide on the page, and the screen shows one. Rather than draw the whole deck twice,
   * the deck is drawn in full only while the browser prints — whether the button asked for it or the reader
   * pressed the keyboard shortcut, which `beforeprint` covers.
   */
  const [printing, setPrinting] = useState(false);

  useEffect(() => {
    const before = () => flushSync(() => setPrinting(true));
    const after = () => setPrinting(false);
    window.addEventListener('beforeprint', before);
    window.addEventListener('afterprint', after);

    return () => {
      window.removeEventListener('beforeprint', before);
      window.removeEventListener('afterprint', after);
    };
  }, []);

  const vocabulary = usePresentationVocabularyQuery(canUse);
  const clients = useClientsQuery({ search: clientTerm, per_page: 8 });
  const budgets = useBudgetsQuery({}, canUse);

  const kindInfo = useMemo(
    () => (vocabulary.data?.kinds ?? []).find((entry) => entry.kind === kind) ?? null,
    [vocabulary.data, kind],
  );

  const fromBudget = kindInfo?.built_from_budget ?? false;

  const deck = useDeckQuery(
    kind,
    fromBudget ? null : clientId,
    fromBudget ? budgetId : null,
    canUse,
  );

  const error = deck.error instanceof ApiError ? deck.error.message : null;

  if (!canUse) {
    return (
      <EmptyState
        title={t('presentations.denied.title')}
        description={t('presentations.denied.body')}
      />
    );
  }

  const subjectChosen = fromBudget ? budgetId !== null : clientId !== null;
  const budgetList = budgets.data ?? [];

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        className="ds-no-print"
        title={t('presentations.title')}
        description={t('presentations.subtitle')}
      />

      <div className="ds-no-print flex flex-col gap-4">
        <ChoiceGroup label={t('presentations.deck')}>
          <div className="flex flex-wrap gap-2">
            {(vocabulary.data?.kinds ?? []).map((entry) => (
              <Choice
                key={entry.kind}
                isSelected={kind === entry.kind}
                onSelect={() => setKind(entry.kind)}
              >
                {entry.label}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>

        {kindInfo === null ? null : (
          <p className="text-content-tertiary text-sm">{kindInfo.description}</p>
        )}

        {fromBudget ? (
          budgetList.length === 0 ? (
            <Alert tone="info">{t('presentations.noBudgets')}</Alert>
          ) : (
            <ChoiceGroup label={t('presentations.budget')}>
              <div className="flex flex-wrap gap-2">
                {budgetList.map((budget: BudgetPayload) => (
                  <Choice
                    key={budget.id}
                    isSelected={budgetId === budget.id}
                    onSelect={() => setBudgetId(budget.id)}
                  >
                    {budget.client_name} — {budget.name}
                  </Choice>
                ))}
              </div>
            </ChoiceGroup>
          )
        ) : (
          <EntityPicker
            label={t('presentations.client')}
            clearLabel={t('actions.clear')}
            value={client}
            onChange={(option) =>
              setClient(option === null ? null : { id: option.id, label: option.label })
            }
            onSearch={setClientTerm}
            options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
            isLoading={clients.isFetching}
          />
        )}
      </div>

      {error === null ? null : (
        <Alert tone="danger" className="ds-no-print">
          {error}
        </Alert>
      )}

      {!subjectChosen ? (
        <EmptyState
          title={t(fromBudget ? 'presentations.pickBudget.title' : 'presentations.pick.title')}
          description={t(fromBudget ? 'presentations.pickBudget.body' : 'presentations.pick.body')}
        />
      ) : deck.isPending ? (
        <Skeleton className="h-96 w-full" />
      ) : deck.data === undefined ? null : (
        <>
          <div className="ds-no-print flex flex-wrap items-center gap-3">
            <SourceBadge source={deck.data.source as MetricSourceValue} />
            <span className="text-content-tertiary text-xs">
              {t('presentations.prepared', {
                at: new Date(deck.data.prepared_at).toLocaleString(locale),
                source: deck.data.source_label,
              })}
            </span>

            <span className="ms-auto flex flex-wrap gap-2">
              <Button
                variant="secondary"
                size="sm"
                onClick={() => {
                  flushSync(() => setPrinting(true));
                  window.print();
                }}
              >
                <Printer aria-hidden className="me-1 size-3.5" />
                {t('presentations.actions.print')}
              </Button>
              {/* A plain link: the browser downloads it, rather than JavaScript holding a deck in memory. */}
              <a
                href={pptxUrl({
                  kind,
                  clientId: fromBudget ? null : clientId,
                  budgetId: fromBudget ? budgetId : null,
                })}
                className="bg-accent text-accent-contrast hover:bg-accent-strong focus-visible:ring-accent/40 inline-flex h-8 items-center rounded-md px-3 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
              >
                <Download aria-hidden className="me-1 size-3.5" />
                {t('presentations.actions.pptx')}
              </a>
            </span>
          </div>

          <p className="ds-no-print text-content-tertiary text-xs">
            {t('presentations.actions.printHint')}
          </p>

          {/*
            Said on the page. The theme is tokens, and until a reference deck exists they are the product's own —
            which is a placeholder that reads as deliberate rather than as a brand nobody chose.
          */}
          {!deck.data.theme.is_provisional ? null : (
            <Alert tone="info" className="ds-no-print flex items-start gap-2">
              <Info aria-hidden className="mt-0.5 size-4 shrink-0" />
              <span>{t('presentations.theme.provisional', { name: deck.data.theme.name })}</span>
            </Alert>
          )}

          <section
            aria-label={deck.data.title}
            className={cn(
              'ds-deck',
              printing ? 'flex flex-col gap-6' : 'grid gap-4 lg:grid-cols-[12rem_minmax(0,1fr)]',
            )}
          >
            {printing ? (
              deck.data.slides.map((slide, index) => (
                <SlideView
                  key={`${slide.kind}-${index}`}
                  slide={slide}
                  theme={deck.data.theme}
                  index={index}
                  total={deck.data.slides.length}
                />
              ))
            ) : (
              <>
                <ol
                  aria-label={t('presentations.slides')}
                  className="ds-no-print flex gap-2 overflow-x-auto pb-1 lg:max-h-[34rem] lg:flex-col lg:overflow-x-visible lg:overflow-y-auto"
                >
                  {deck.data.slides.map((slide, index) => (
                    <li key={`${slide.kind}-${index}`} className="shrink-0">
                      <button
                        type="button"
                        aria-current={active === index ? 'true' : undefined}
                        onClick={() => setActive(index)}
                        className={cn(
                          'bg-surface border-line-subtle flex w-40 flex-col gap-1 rounded-lg border p-2 text-start lg:w-full',
                          'hover:border-line-strong focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
                          active === index && 'border-accent ring-accent/25 ring-1',
                        )}
                      >
                        <span className="text-content-tertiary text-[10px] tabular-nums">
                          {index + 1}
                        </span>
                        <span className="text-content line-clamp-2 text-xs font-medium" dir="auto">
                          {slide.title}
                        </span>
                      </button>
                    </li>
                  ))}
                </ol>
                <div className="flex min-w-0 flex-col gap-3">
                  {deck.data.slides[active] === undefined ? null : (
                    <SlideView
                      slide={deck.data.slides[active]}
                      theme={deck.data.theme}
                      index={active}
                      total={deck.data.slides.length}
                    />
                  )}
                  <div className="ds-no-print flex items-center justify-between gap-3">
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={active === 0}
                      onClick={() => setActive((value) => Math.max(0, value - 1))}
                    >
                      <ChevronLeft aria-hidden className="rtl:rotate-180" />
                      {t('presentations.previous')}
                    </Button>
                    <span className="text-content-secondary text-sm tabular-nums">
                      {active + 1} / {deck.data.slides.length}
                    </span>
                    <Button
                      variant="ghost"
                      size="sm"
                      disabled={active >= deck.data.slides.length - 1}
                      onClick={() =>
                        setActive((value) => Math.min(deck.data.slides.length - 1, value + 1))
                      }
                    >
                      {t('presentations.next')}
                      <ChevronRight aria-hidden className="rtl:rotate-180" />
                    </Button>
                  </div>
                </div>
              </>
            )}
          </section>

          {(vocabulary.data?.deferred ?? []).length === 0 ? null : (
            <section
              aria-label={t('presentations.deferred.title')}
              className="ds-no-print flex flex-col gap-2"
            >
              <h2 className="text-content text-sm font-semibold">
                {t('presentations.deferred.title')}
              </h2>
              <p className="text-content-tertiary text-sm">{t('presentations.deferred.body')}</p>
              <ul className="flex flex-col gap-2">
                {(vocabulary.data?.deferred ?? []).map((entry) => (
                  <li key={entry.kind} className="text-sm">
                    <span className="text-content font-medium">{entry.label}</span>
                    <span className="text-content-secondary"> — {entry.because}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </>
      )}
    </div>
  );
}
