import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/card';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Textarea } from '@/shared/ui/textarea';
import { formatMinor } from './money';
import type { Lead, LeadOwnerCandidate } from './types';

type LeadWorkPanelProps = {
  lead: Lead | null;
  owners: LeadOwnerCandidate[] | undefined;
  onAssign: (ownerId: string | null) => void;
  onSetValue: (valueMinor: number | null, currency: string | null) => void;
  onAddNote: (note: string) => void;
  /** Hidden for a role the server would refuse anyway — a courtesy, not the enforcement. */
  canManage: boolean;
  isBusy: boolean;
  error: string | null;
};

/** The currency a new value is entered in, where the lead has none yet. */
const CURRENCIES = ['SAR', 'AED', 'USD'] as const;

/**
 * The three things a pipeline is actually worked with: who is answering, what it is worth, and what happened.
 *
 * Each one exists because the pipeline list already had a column for it and nothing in the product could fill
 * it. An owner column nothing can set names whoever imported the lead; a value column nothing can set is empty
 * on every row a CRM did not produce; and without notes the only record of a conversation is the stage it ended
 * in, which turns a pipeline review into six people remembering differently.
 *
 * Three rules are visible here rather than only enforced on the server:
 *
 *  - **The owner list is the server's.** It holds only colleagues who can actually open this lead, because a
 *    lead carries a prospect's name and phone number — so every name offered is one the server will accept.
 *  - **Clearing a value is offered separately from setting one**, because null and zero are different claims:
 *    nobody has said, versus somebody saying it is worth nothing.
 *  - **A CRM-sourced lead's value is not editable here**, and the sentence goes where the control would have
 *    been. The CRM holds the amount, and a figure typed here would be undone by the next sync — after both
 *    numbers had been quoted to the same client.
 */
export function LeadWorkPanel({
  lead,
  owners,
  onAssign,
  onSetValue,
  onAddNote,
  canManage,
  isBusy,
  error,
}: LeadWorkPanelProps) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState<string>(CURRENCIES[0]);
  const [note, setNote] = useState('');

  if (lead === null || !canManage) {
    return null;
  }

  const isOwnedByTheCrm = lead.source_kind === 'crm';

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('leads.work.title')}</CardTitle>
        <CardDescription>{t('leads.work.subtitle')}</CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        {error === null ? null : <Alert tone="danger">{error}</Alert>}

        {/*
          `Field` rather than a hand-written label, and not for tidiness: it wires the hint as a *description*
          (`aria-describedby`) instead of letting it be folded into the control's accessible name. A screen
          reader on the hand-written version read the whole sentence back as the name of the field every time it
          took focus — found by the test that asked for a combobox called "Owner".
        */}
        <Field label={t('leads.work.owner')} hint={t('leads.work.ownerHint')}>
          {(props) => (
            <select
              {...props}
              className="border-line bg-surface text-content rounded-lg border px-3 py-2 text-sm"
              value={lead.owner_id ?? ''}
              disabled={isBusy}
              onChange={(event) => onAssign(event.target.value === '' ? null : event.target.value)}
            >
              {/*
                Unassigning is a first-class choice, not an absence. A pipeline that can only ever add an owner
                accumulates leads belonging to whoever touched them first, and every workload figure after that
                describes a team that does not exist.
              */}
              <option value="">{t('leads.work.nobody')}</option>
              {(owners ?? []).map((person) => (
                <option key={person.id} value={person.id}>
                  {person.name}
                </option>
              ))}
            </select>
          )}
        </Field>

        <div className="border-line-subtle flex flex-col gap-2 border-t pt-4">
          <span className="text-content text-sm font-medium">{t('leads.work.value')}</span>

          {isOwnedByTheCrm ? (
            <p className="text-content-tertiary text-xs">
              {t('leads.work.valueOwnedByTheCrm', {
                source: lead.source_detail ?? lead.source_kind_label,
              })}
            </p>
          ) : (
            <>
              <p className="text-content-secondary text-sm">
                {lead.value_minor === null || lead.currency === null
                  ? t('leads.work.valueUnsaid')
                  : t('leads.work.valueSaid', {
                      amount: formatMinor(lead.value_minor, lead.currency, locale),
                    })}
              </p>

              <form
                className="flex flex-wrap items-end gap-2"
                onSubmit={(event) => {
                  event.preventDefault();

                  const parsed = Number(amount);

                  if (amount.trim() === '' || Number.isNaN(parsed) || parsed < 0) {
                    return;
                  }

                  /* Entered in major units, stored in minor: the server counts in minor everywhere. */
                  onSetValue(Math.round(parsed * 100), lead.currency ?? currency);
                  setAmount('');
                }}
              >
                <Field label={t('leads.work.amount')} className="w-32">
                  {(props) => (
                    <Input
                      {...props}
                      value={amount}
                      inputMode="decimal"
                      onChange={(event) => setAmount(event.target.value)}
                    />
                  )}
                </Field>

                {lead.currency === null ? (
                  <Field label={t('leads.work.currency')}>
                    {(props) => (
                      <select
                        {...props}
                        className="border-line bg-surface text-content rounded-lg border px-3 py-2 text-sm"
                        value={currency}
                        onChange={(event) => setCurrency(event.target.value)}
                      >
                        {CURRENCIES.map((code) => (
                          <option key={code} value={code}>
                            {code}
                          </option>
                        ))}
                      </select>
                    )}
                  </Field>
                ) : null}

                <Button type="submit" size="sm" disabled={isBusy || amount.trim() === ''}>
                  {t('leads.work.saveValue')}
                </Button>

                {lead.value_minor === null ? null : (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={isBusy}
                    onClick={() => onSetValue(null, null)}
                  >
                    {t('leads.work.clearValue')}
                  </Button>
                )}
              </form>

              <p className="text-content-tertiary text-xs">{t('leads.work.valueHint')}</p>
            </>
          )}
        </div>

        <form
          className="border-line-subtle flex flex-col gap-2 border-t pt-4"
          onSubmit={(event) => {
            event.preventDefault();

            if (note.trim() === '') {
              return;
            }

            onAddNote(note.trim());
            setNote('');
          }}
        >
          <Field label={t('leads.work.note')} hint={t('leads.work.noteHint')}>
            {(props) => (
              <Textarea
                {...props}
                value={note}
                rows={3}
                maxLength={2000}
                onChange={(event) => setNote(event.target.value)}
              />
            )}
          </Field>

          <div>
            <Button type="submit" size="sm" disabled={isBusy || note.trim() === ''}>
              {t('leads.work.saveNote')}
            </Button>
          </div>
        </form>
      </CardContent>
    </Card>
  );
}
