import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { formatMinor } from '@/shared/lib/format';
import { formatRelativeTime } from '@/shared/lib/relative-time';
import { Skeleton } from '@/shared/ui/skeleton';
import type { Lead, LeadStage } from './types';

const COLUMNS: LeadStage[] = ['new', 'contacted', 'qualified', 'won', 'lost', 'disqualified'];

const ACCENT: Record<LeadStage, string> = {
  new: 'border-t-accent',
  contacted: 'border-t-content-tertiary',
  qualified: 'border-t-accent',
  won: 'border-t-success',
  lost: 'border-t-danger',
  disqualified: 'border-t-warning',
};

/** Where a lead may go next — the same short list the table offers, so the two cannot disagree. */
const NEXT: Record<LeadStage, LeadStage[]> = {
  new: ['contacted', 'disqualified'],
  contacted: ['qualified', 'disqualified', 'lost'],
  qualified: ['won', 'lost'],
  disqualified: ['new'],
  won: [],
  lost: ['new'],
};

/**
 * The pipeline as columns, one card per enquiry.
 *
 * What a card carries is deliberately short — who, from where, whose, how old, and a value only when there is
 * one. Everything else is a click away in the detail beside it. Counts in the column heads are of the leads
 * shown, and the funnel above carries the whole period's.
 */
export function PipelineBoard({
  leads,
  isLoading,
  selectedId,
  onSelect,
  onMove,
  canMove,
  locale,
}: {
  leads: Lead[] | undefined;
  isLoading: boolean;
  selectedId: string | null;
  onSelect: (id: string) => void;
  onMove: (id: string, stage: LeadStage) => void;
  canMove: boolean;
  locale: string;
}) {
  const { t } = useTranslation();

  if (isLoading || leads === undefined) {
    return <Skeleton className="h-64 w-full" />;
  }

  return (
    <div
      role="region"
      aria-label={t('leads.board.label')}
      tabIndex={0}
      className="-mx-1 flex snap-x gap-3 overflow-x-auto px-1 pb-2"
    >
      {COLUMNS.map((stage) => {
        const inStage = leads.filter((l) => l.stage === stage);

        return (
          <section
            key={stage}
            aria-label={t(`leads.board.stage.${stage}`)}
            className={cn(
              'bg-surface-sunken flex w-64 shrink-0 snap-start flex-col gap-2 rounded-xl border-t-2 p-2.5',
              ACCENT[stage],
            )}
          >
            <header className="flex items-center justify-between px-1">
              <h3 className="text-content text-sm font-semibold">
                {t(`leads.board.stage.${stage}`)}
              </h3>
              <span className="ds-numeric text-content-tertiary text-xs">{inStage.length}</span>
            </header>

            {inStage.length === 0 ? (
              <p className="text-content-tertiary px-1 py-3 text-xs">{t('leads.board.empty')}</p>
            ) : (
              <ul className="flex flex-col gap-2">
                {inStage.map((lead) => (
                  <li key={lead.id}>
                    <article
                      className={cn(
                        'bg-surface border-line-subtle flex flex-col gap-1.5 rounded-lg border p-3 text-sm shadow-xs',
                        selectedId === lead.id && 'ring-accent ring-2',
                      )}
                    >
                      <button
                        type="button"
                        onClick={() => onSelect(lead.id)}
                        aria-pressed={selectedId === lead.id}
                        className="focus-visible:ring-accent/40 rounded text-start focus-visible:ring-2 focus-visible:outline-none"
                      >
                        <span className="text-content block truncate font-medium">
                          {lead.name ?? t('leads.board.unnamed')}
                        </span>
                        <span className="text-content-tertiary block truncate text-xs">
                          {[lead.source_kind_label, lead.utm_campaign].filter(Boolean).join(' · ')}
                        </span>
                      </button>
                      <div className="text-content-secondary flex items-center justify-between text-xs">
                        <span className="truncate">
                          {lead.owner_name ?? t('leads.board.unowned')}
                        </span>
                        <span>{formatRelativeTime(lead.received_at, locale)}</span>
                      </div>
                      {lead.value_minor !== null && lead.currency !== null ? (
                        <span className="ds-numeric text-content text-xs font-medium">
                          {formatMinor(lead.value_minor, lead.currency, locale)}
                        </span>
                      ) : null}
                      {canMove && NEXT[stage].length > 0 ? (
                        <select
                          aria-label={t('leads.board.moveTo', { name: lead.name ?? '' })}
                          className="border-line bg-surface h-7 rounded-md border px-1.5 text-xs"
                          value=""
                          onChange={(event) => {
                            if (event.target.value !== '') {
                              onMove(lead.id, event.target.value as LeadStage);
                            }
                          }}
                        >
                          <option value="">{t('leads.board.move')}</option>
                          {NEXT[stage].map((next) => (
                            <option key={next} value={next}>
                              {t(`leads.board.stage.${next}`)}
                            </option>
                          ))}
                        </select>
                      ) : null}
                    </article>
                  </li>
                ))}
              </ul>
            )}
          </section>
        );
      })}
    </div>
  );
}
