import { Clock } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatNumber } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { ScrollRegion } from '@/shared/ui/scroll-region';
import { formatMinor } from './money';
import type { Lead, LeadStage } from './types';

type PipelineListProps = {
  leads: Lead[] | undefined;
  isLoading: boolean;
  selectedId: string | null;
  onSelect: (leadId: string) => void;
  onMove: (leadId: string, stage: LeadStage, reason?: string) => void;
  /** Hidden for a role the server would refuse anyway — a courtesy, not the enforcement. */
  canMove: boolean;
  moveError: string | null;
};

/** Where a lead can go next. Deliberately short: a pipeline with twelve buttons gets none used. */
const NEXT_STAGES: Record<LeadStage, LeadStage[]> = {
  new: ['contacted', 'disqualified'],
  contacted: ['qualified', 'disqualified', 'lost'],
  qualified: ['won', 'lost'],
  disqualified: ['new'],
  won: [],
  lost: ['new'],
};

const STAGE_TONE: Record<LeadStage, 'neutral' | 'accent' | 'success' | 'warning' | 'danger'> = {
  new: 'accent',
  contacted: 'neutral',
  qualified: 'accent',
  disqualified: 'warning',
  won: 'success',
  lost: 'danger',
};

/**
 * The pipeline itself.
 *
 * A table rather than a board. A board is better for dragging one lead along and worse for
 * every question this page exists to answer — who has not been rung, which route is
 * producing, how long people are waiting — and this product's readers are agency staff
 * reviewing a month, not a salesperson working a queue.
 *
 * **Time to first contact is a column, not a detail.** A lead rung within the hour converts
 * at a different rate from one rung next week, and without this figure an agency cannot tell
 * a bad campaign from a slow sales team — which is the single most common way a client is
 * told the wrong thing about their marketing.
 */
export function PipelineList({
  leads,
  isLoading,
  selectedId,
  onSelect,
  onMove,
  canMove,
  moveError,
}: PipelineListProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const [disqualifying, setDisqualifying] = useState<string | null>(null);
  const [reason, setReason] = useState('');

  if (isLoading) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('leads.list.title')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2">
          {[0, 1, 2, 3].map((row) => (
            <Skeleton key={row} className="h-12 w-full" />
          ))}
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('leads.list.title')}</CardTitle>
        <CardDescription>{t('leads.list.subtitle')}</CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-3">
        {moveError === null ? null : <Alert tone="danger">{moveError}</Alert>}

        {leads === undefined || leads.length === 0 ? (
          <EmptyState title={t('leads.list.emptyTitle')} description={t('leads.list.emptyBody')} />
        ) : (
          <ScrollRegion label={t('leads.list.caption')}>
            <table className="w-full border-collapse text-sm">
              <caption className="sr-only">{t('leads.list.caption')}</caption>
              <thead>
                <tr className="border-line-subtle border-b">
                  {[
                    t('leads.table.lead'),
                    t('leads.table.route'),
                    t('leads.table.stage'),
                    t('leads.table.received'),
                    t('leads.table.toFirstContact'),
                    t('leads.table.value'),
                    t('leads.table.actions'),
                  ].map((column, index) => (
                    <th
                      key={column}
                      scope="col"
                      className={`text-content-tertiary px-2 py-2 text-xs font-medium tracking-wide uppercase ${
                        index >= 3 && index <= 5 ? 'text-end' : 'text-start'
                      }`}
                    >
                      {column}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {leads.map((lead) => (
                  <tr
                    key={lead.id}
                    className={`border-line-subtle border-b last:border-0 ${
                      selectedId === lead.id ? 'bg-accent-subtle' : ''
                    }`}
                  >
                    <th scope="row" className="px-2 py-2 text-start font-normal">
                      <button
                        type="button"
                        onClick={() => onSelect(lead.id)}
                        className="text-content-brand text-start text-sm font-medium hover:underline focus-visible:ring-2 focus-visible:ring-[var(--ds-focus-ring)] focus-visible:outline-none"
                      >
                        {lead.name ?? lead.phone ?? lead.email ?? t('leads.table.anonymous')}
                      </button>
                      {lead.client_name === null ? null : (
                        <span className="text-content-tertiary block text-xs">
                          {lead.client_name}
                        </span>
                      )}
                    </th>

                    <td className="px-2 py-2">
                      <span className="flex flex-wrap items-center gap-1.5">
                        <span className="text-content-secondary">{lead.source_kind_label}</span>
                        {/*
                          A hand-entered lead is a real lead and must never be reported as a
                          campaign result. Marked on every row rather than only in the breakdown.
                        */}
                        {lead.is_measured ? null : (
                          <Badge tone="warning">{t('leads.badge.notMeasured')}</Badge>
                        )}
                      </span>
                    </td>

                    <td className="px-2 py-2">
                      <Badge tone={STAGE_TONE[lead.stage]}>{lead.stage_label}</Badge>
                      {lead.disqualified_reason === null ? null : (
                        <span className="text-content-tertiary block text-xs">
                          {lead.disqualified_reason}
                        </span>
                      )}
                    </td>

                    <td className="text-content-secondary px-2 py-2 text-end tabular-nums">
                      {new Date(lead.received_at).toLocaleDateString(locale, {
                        dateStyle: 'medium',
                      })}
                    </td>

                    <td className="px-2 py-2 text-end tabular-nums">
                      {lead.hours_to_first_contact === null ? (
                        /*
                          Nobody has tried yet — which is not "0 hours", and is the most
                          actionable thing on this row.
                        */
                        <span className="text-warning flex items-center justify-end gap-1 text-xs">
                          <Clock aria-hidden className="size-3.5" />
                          {t('leads.table.notContacted')}
                        </span>
                      ) : (
                        <span className="text-content-secondary">
                          {t('leads.table.hours', {
                            hours: formatNumber(lead.hours_to_first_contact, locale, 1),
                          })}
                        </span>
                      )}
                    </td>

                    <td className="text-content-secondary px-2 py-2 text-end tabular-nums">
                      {lead.value_minor === null || lead.currency === null
                        ? '—'
                        : formatMinor(lead.value_minor, lead.currency, locale)}
                    </td>

                    <td className="px-2 py-2">
                      {/*
                        A lead that came from a CRM is a read of that CRM, and the CRM owns its stage. The
                        sentence goes where the controls would have been rather than the controls being hidden: a
                        move made here would be undone by the next sync without anybody being told, and a reader
                        who cannot see why the buttons are missing concludes the product is broken.
                      */}
                      {lead.source_kind === 'crm' ? (
                        <span className="text-content-tertiary text-xs">
                          {t('leads.list.ownedByTheCrm', {
                            source: lead.source_detail ?? lead.source_kind_label,
                          })}
                        </span>
                      ) : !canMove || NEXT_STAGES[lead.stage].length === 0 ? (
                        <span className="text-content-tertiary text-xs">—</span>
                      ) : disqualifying === lead.id ? (
                        /*
                          A disqualification needs a reason. The server refuses one without it,
                          so the interface asks for it rather than sending a request it knows
                          will fail — and the reason is the only record of why somebody judged a
                          lead worthless.
                        */
                        <form
                          className="flex flex-col gap-2"
                          onSubmit={(event) => {
                            event.preventDefault();
                            onMove(lead.id, 'disqualified', reason);
                            setDisqualifying(null);
                            setReason('');
                          }}
                        >
                          <Field label={t('leads.list.reason')} required>
                            {(props) => (
                              <Input
                                {...props}
                                value={reason}
                                onChange={(event) => setReason(event.target.value)}
                                required
                              />
                            )}
                          </Field>
                          <div className="flex gap-2">
                            <Button type="submit" size="sm">
                              {t('leads.list.confirm')}
                            </Button>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              onClick={() => setDisqualifying(null)}
                            >
                              {t('actions.cancel')}
                            </Button>
                          </div>
                        </form>
                      ) : (
                        <div className="flex flex-wrap gap-1.5">
                          {NEXT_STAGES[lead.stage].map((stage) => (
                            <Button
                              key={stage}
                              type="button"
                              variant="ghost"
                              size="sm"
                              onClick={() =>
                                stage === 'disqualified'
                                  ? setDisqualifying(lead.id)
                                  : onMove(lead.id, stage)
                              }
                            >
                              {t(`leads.stages.${stage}`)}
                            </Button>
                          ))}
                        </div>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </ScrollRegion>
        )}
      </CardContent>
    </Card>
  );
}
