import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Check, Megaphone, Palette, Radar, Wallet, X } from 'lucide-react';
import { useDecideApprovalMutation, type ApprovalRow } from '@/features/approvals';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { AgainstTarget, BeforeAfter, DecisionCard } from '@/shared/ui/decision-card';
import { Input } from '@/shared/ui/input';
import { APPROVAL_TYPE, type ActionType } from './kinds';

const ICON: Record<ActionType, React.ReactNode> = {
  budget: <Wallet />,
  campaign: <Megaphone />,
  creative: <Palette />,
  tracking: <Radar />,
  task: <Check />,
  request: <Check />,
};

const num = (value: unknown): number | null =>
  typeof value === 'number' && Number.isFinite(value) ? value : null;

const text = (value: unknown): string | null =>
  typeof value === 'string' && value.trim() !== '' ? value : null;

/**
 * An approval as one decision.
 *
 * Preview, then decide: the picture of the change is the card's face, **Approve** and **Reject** are one click each,
 * and **Change** opens a single field for what should be different. The proposal's raw fields and the requester's
 * rationale are behind *Details*; nobody has to read them to decide a budget goes from 20,000 to 25,000.
 *
 * Approving authorises; it does not carry anything out on a platform. That is still a second, separate act, by a
 * person with its own permission — nothing here moves spend.
 */
export function ApprovalDecision({ row, locale }: { row: ApprovalRow; locale: string }) {
  const { t } = useTranslation();
  const decide = useDecideApprovalMutation();
  const [changing, setChanging] = useState(false);
  const [note, setNote] = useState('');

  const type = APPROVAL_TYPE[row.kind];
  const proposal = row.proposal;
  const current = num(proposal['current']);
  const requested = num(proposal['requested']);
  const currency = text(proposal['currency']);
  const money = (value: number) =>
    currency === null
      ? new Intl.NumberFormat(locale).format(value)
      : new Intl.NumberFormat(locale, {
          style: 'currency',
          currency,
          maximumFractionDigits: 0,
        }).format(value);

  const scope = [row.client_name, row.project_name].filter(Boolean).join(' · ');
  /* The common words a proposal carries are the product's own, so they translate; anything else is shown as written. */
  const rawChange = text(proposal['change']);
  const change =
    rawChange === null
      ? null
      : t(`actionCenter.changeWord.${rawChange.toLowerCase().replace(/[^a-z]+/g, '_')}`, {
          defaultValue: rawChange,
        });

  const evidence = row.evidence ?? {};
  const cost = num(evidence['cost_per_result_minor']);
  const targetCost = num(evidence['target_cpa_minor']);
  const roas = num(evidence['roas']);
  const targetRoas = num(evidence['target_roas']);
  const evidenceCurrency = text(evidence['currency']);
  const minorMoney = (minor: number) =>
    evidenceCurrency === null
      ? new Intl.NumberFormat(locale).format(minor / 100)
      : new Intl.NumberFormat(locale, {
          style: 'currency',
          currency: evidenceCurrency,
          maximumFractionDigits: 0,
        }).format(minor / 100);

  const visual =
    current !== null && requested !== null ? (
      <BeforeAfter
        beforeLabel={t('actionCenter.now')}
        afterLabel={t('actionCenter.requested')}
        before={money(current)}
        after={money(requested)}
        difference={`${requested >= current ? '+' : '−'}${money(Math.abs(requested - current))}`}
        positive={requested >= current}
      />
    ) : cost !== null && targetCost !== null ? (
      <AgainstTarget
        label={t('actionCenter.costPerResult')}
        value={minorMoney(cost)}
        target={minorMoney(targetCost)}
        targetLabel={t('actionCenter.target')}
        ratio={Math.min(1, targetCost / cost)}
      />
    ) : roas !== null && targetRoas !== null ? (
      <AgainstTarget
        label="ROAS"
        value={`${roas.toFixed(2)}×`}
        target={`${targetRoas.toFixed(2)}×`}
        targetLabel={t('actionCenter.target')}
        ratio={roas / targetRoas}
      />
    ) : type === 'creative' ? (
      <div className="bg-surface-sunken text-content-secondary grid aspect-[16/9] place-items-center rounded-2xl text-sm">
        <span className="flex flex-col items-center gap-1">
          <Palette aria-hidden className="size-8" />
          <span>
            {[text(proposal['format']), text(proposal['size'])].filter(Boolean).join(' · ')}
          </span>
        </span>
      </div>
    ) : num(proposal['budget']) !== null ? (
      <div className="bg-surface-sunken flex items-baseline justify-between gap-3 rounded-2xl p-4">
        <span className="text-content font-medium" dir="auto">
          {change ?? t('actionCenter.launch')}
        </span>
        <span className="ds-numeric text-content text-lg font-semibold">
          {money(num(proposal['budget']) ?? 0)}
        </span>
      </div>
    ) : change !== null ? (
      <p className="bg-surface-sunken text-content rounded-2xl p-4 text-sm font-medium" dir="auto">
        {change}
      </p>
    ) : null;

  const error =
    decide.error instanceof ApiError
      ? decide.error.message
      : decide.isError
        ? t('actionCenter.failed')
        : null;

  const send = (outcome: 'approved' | 'declined', reason?: string) =>
    decide.mutate({
      requestId: row.id,
      outcome,
      fingerprint: row.fingerprint,
      ...(reason === undefined ? {} : { reason }),
    });

  return (
    <DecisionCard
      icon={ICON[type]}
      type={t(`actionCenter.type.${type}`)}
      tone="attention"
      scope={scope}
      headline={row.title}
      byline={
        row.origin === 'agent'
          ? t('actionCenter.preparedBy', {
              agent: t(`actionCenter.agents.${row.agent ?? 'assistant'}`, {
                defaultValue: row.agent ?? '',
              }),
            })
          : undefined
      }
      details={{ to: `/approvals?open=${row.id}`, label: t('actionCenter.details') }}
      visual={
        <>
          {visual}
          {error === null ? null : <Alert tone="danger">{error}</Alert>}
          {changing ? (
            <form
              className="flex gap-2"
              onSubmit={(event) => {
                event.preventDefault();

                if (note.trim() !== '') {
                  send('declined', `${t('actionCenter.changePrefix')} ${note.trim()}`);
                }
              }}
            >
              <Input
                autoFocus
                value={note}
                onChange={(event) => setNote(event.target.value)}
                placeholder={t('actionCenter.changeWhat')}
                aria-label={t('actionCenter.changeWhat')}
              />
              <Button type="submit" disabled={note.trim() === '' || decide.isPending}>
                {t('actionCenter.send')}
              </Button>
            </form>
          ) : null}
        </>
      }
      actions={
        <>
          <Button disabled={decide.isPending} onClick={() => send('approved')}>
            <Check />
            {t('actionCenter.approve')}
          </Button>
          <Button
            variant="secondary"
            disabled={decide.isPending}
            onClick={() => setChanging((open) => !open)}
          >
            {t('actionCenter.change')}
          </Button>
          <Button
            variant="ghost"
            disabled={decide.isPending}
            onClick={() => send('declined', t('actionCenter.rejectedReason'))}
          >
            <X />
            {t('actionCenter.reject')}
          </Button>
        </>
      }
    />
  );
}
