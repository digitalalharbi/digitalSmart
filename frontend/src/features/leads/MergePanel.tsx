import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import type { Lead, LeadDuplicate, LeadStage } from './types';

type MergePanelProps = {
  lead: Lead | null;
  duplicates: LeadDuplicate[] | undefined;
  isLoading: boolean;
  onMerge: (otherLeadId: string, stage: LeadStage, note?: string) => void;
  isMerging: boolean;
  /** Hidden for a role the server would refuse anyway — a courtesy, not the enforcement. */
  canMerge: boolean;
  error: string | null;
};

/**
 * Two leads a human knows are one person.
 *
 * **The candidates are the server's, and each says what matched.** Exact matches on a normalised email address
 * or phone number, nothing else: no fuzzy names, no score, no "looks similar". A merge is not reversible by
 * anything this product offers, so the evidence is on the row the reader is about to act on.
 *
 * **The surviving stage is asked for, not inferred.** A lead that bought and a lead that enquired again are both
 * real, and which one the merged record is in is not derivable from either — so the control offers exactly the
 * two stages the two leads are in and no others. A third would move a lead through a pipeline without recording
 * a move, and the review that asks "who decided this, and when" would find nothing.
 *
 * **What the merge will do is stated before it is done**, because afterwards there is nothing to read it from:
 * which record continues, that the earlier arrival time is kept, and that nothing is deleted.
 */
export function MergePanel({
  lead,
  duplicates,
  isLoading,
  onMerge,
  isMerging,
  canMerge,
  error,
}: MergePanelProps) {
  const { t } = useTranslation();
  const [chosen, setChosen] = useState<LeadDuplicate | null>(null);
  const [stage, setStage] = useState<LeadStage | null>(null);
  const [note, setNote] = useState('');

  if (lead === null) {
    return null;
  }

  if (isLoading) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>{t('leads.merge.title')}</CardTitle>
        </CardHeader>
        <CardContent>
          <Skeleton className="h-20 w-full" />
        </CardContent>
      </Card>
    );
  }

  /*
   * Absent rather than empty. A panel saying "no duplicates found" on every lead teaches a reader to ignore it,
   * and the one time it has something to say it will be ignored too.
   */
  if (duplicates === undefined || duplicates.length === 0) {
    return null;
  }

  /* The two stages the merged record may be in, deduplicated where both leads are in the same one. */
  const stages: { value: LeadStage; label: string }[] =
    chosen === null
      ? []
      : chosen.stage === lead.stage
        ? [{ value: lead.stage, label: lead.stage_label }]
        : [
            { value: lead.stage, label: lead.stage_label },
            { value: chosen.stage, label: chosen.stage_label },
          ];

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('leads.merge.title')}</CardTitle>
        <CardDescription>{t('leads.merge.subtitle')}</CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {error === null ? null : <Alert tone="danger">{error}</Alert>}

        <ul className="flex flex-col gap-2">
          {duplicates.map((duplicate) => (
            <li
              key={duplicate.id}
              className="border-line-subtle flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
            >
              <div className="flex flex-col gap-1">
                <span className="text-content text-sm font-medium">
                  {duplicate.name ??
                    duplicate.phone ??
                    duplicate.email ??
                    t('leads.table.anonymous')}
                </span>
                <span className="text-content-secondary text-xs">
                  {t('leads.merge.arrived', {
                    date: new Date(duplicate.received_at).toLocaleDateString(undefined, {
                      dateStyle: 'medium',
                    }),
                    route: duplicate.source_detail ?? duplicate.source_kind_label,
                    stage: duplicate.stage_label,
                  })}
                </span>
                {/*
                  Why this row is here at all. The claim is exact and the label says so — "same phone number",
                  never "similar".
                */}
                <Badge tone={duplicate.matched_on === 'email_and_phone' ? 'accent' : 'neutral'}>
                  {duplicate.matched_on_label}
                </Badge>
              </div>

              {canMerge ? (
                <Button
                  type="button"
                  variant={chosen?.id === duplicate.id ? 'secondary' : 'ghost'}
                  size="sm"
                  onClick={() => {
                    setChosen(duplicate);
                    setStage(null);
                  }}
                >
                  {t('leads.merge.choose')}
                </Button>
              ) : (
                /* Said, not hidden: a reader who cannot see why there is no control concludes it is broken. */
                <span className="text-content-tertiary text-xs">{t('leads.merge.notYours')}</span>
              )}
            </li>
          ))}
        </ul>

        {chosen === null ? null : (
          <form
            className="border-line-subtle flex flex-col gap-3 rounded-lg border p-3"
            onSubmit={(event) => {
              event.preventDefault();

              if (stage === null) {
                return;
              }

              onMerge(chosen.id, stage, note.trim() === '' ? undefined : note.trim());
              setChosen(null);
              setStage(null);
              setNote('');
            }}
          >
            {/*
              What is about to happen, in words, before it happens. There is no undo, and afterwards the two
              histories are on one record with nothing left to read this from.
            */}
            <Alert tone="warning">{t('leads.merge.consequence')}</Alert>

            <ChoiceGroup label={t('leads.merge.stage')} hint={t('leads.merge.stageHint')}>
              <div className="flex flex-wrap gap-2">
                {stages.map((option) => (
                  <Choice
                    key={option.value}
                    isSelected={stage === option.value}
                    onSelect={() => setStage(option.value)}
                  >
                    {option.label}
                  </Choice>
                ))}
              </div>
            </ChoiceGroup>

            <Field label={t('leads.merge.note')} hint={t('leads.merge.noteHint')}>
              {(props) => (
                <Input
                  {...props}
                  value={note}
                  onChange={(event) => setNote(event.target.value)}
                  maxLength={500}
                />
              )}
            </Field>

            <div className="flex flex-wrap gap-2">
              <Button type="submit" size="sm" disabled={stage === null || isMerging}>
                {t('leads.merge.confirm')}
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => {
                  setChosen(null);
                  setStage(null);
                }}
              >
                {t('actions.cancel')}
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
