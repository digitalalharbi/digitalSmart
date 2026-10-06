import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Check, Plus, Save, Trash2, X } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { Textarea } from '@/shared/ui/textarea';
import { formatCurrency } from '@/shared/lib/format';
import { cn } from '@/shared/lib/cn';
import { AllocationEditor } from './AllocationEditor';
import { toDecimal } from './money';
import { PacingPanel } from './PacingPanel';
import {
  useApproveBudget,
  useArchiveBudget,
  useBudgetAlertsQuery,
  useBudgetPacingQuery,
  useBudgetQuery,
  useBudgetsQuery,
  useDeleteBudget,
  useSaveAllocations,
  useSaveBudget,
} from './useBudgets';
import type {
  AllocationDraft,
  AllocationPayload,
  BudgetDraft,
  BudgetPayload,
  BudgetStatusValue,
} from './types';

const STATUS_TONE: Record<BudgetStatusValue, 'neutral' | 'success' | 'warning'> = {
  draft: 'warning',
  approved: 'success',
  archived: 'neutral',
};

/**
 * The media planner: what was agreed, how it is split, and how it is running.
 *
 * One surface rather than three, because those are one conversation. A planner looking at
 * an overspending platform wants to move money *now*, and sending them to another screen
 * to do it is how allocations end up out of date.
 *
 * The approve control is hidden from people without `budgets.approve`, which is a
 * courtesy and not the boundary — the server refuses it either way.
 */
export function MediaPlannerPage() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const canView = can(PERMISSIONS.budgetsView);
  const canManage = can(PERMISSIONS.budgetsManage);
  const canApprove = can(PERMISSIONS.budgetsApprove);
  const locale = i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : 'en';

  const budgets = useBudgetsQuery({}, canView);
  const alerts = useBudgetAlertsQuery(canView);

  const [chosenId, setChosenId] = useState<string | null>(null);
  const [isCreating, setIsCreating] = useState(false);
  const [draft, setDraft] = useState<BudgetDraft | null>(null);
  const [allocations, setAllocations] = useState<AllocationDraft[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  /*
   * Derived rather than synced through an effect. An effect that selected a budget while
   * a draft was open would quietly change what Save writes to — the same bug the
   * dashboard builder had, and the reason this is a `useMemo`.
   */
  const landingId = useMemo(() => budgets.data?.[0]?.id ?? null, [budgets.data]);
  const selectedId = isCreating ? null : (chosenId ?? landingId);

  const detail = useBudgetQuery(selectedId);
  const pacing = useBudgetPacingQuery(selectedId);

  const saveBudget = useSaveBudget();
  const saveAllocations = useSaveAllocations();
  const approve = useApproveBudget();
  const archive = useArchiveBudget();
  const remove = useDeleteBudget();

  const budget = detail.data ?? null;

  if (!canView) {
    return (
      <EmptyState
        title={t('budgets.forbidden.title')}
        description={t('budgets.forbidden.description')}
      />
    );
  }

  const startDraft = () => {
    setIsCreating(true);
    setAllocations(null);
    setError(null);
    setDraft(emptyDraft());
  };

  const editBudget = (payload: BudgetPayload) => {
    setIsCreating(false);
    setError(null);
    setDraft({
      client_id: payload.client_id,
      project_id: payload.project_id,
      name: payload.name,
      period_start: payload.period_start,
      period_end: payload.period_end,
      amount: toDecimal(payload.amount_minor),
      currency: payload.currency,
      notes: payload.notes ?? null,
    });
  };

  const cancel = () => {
    setDraft(null);
    setAllocations(null);
    setIsCreating(false);
    setError(null);
  };

  const commit = async () => {
    if (draft === null) {
      return;
    }

    setError(null);

    try {
      const saved = await saveBudget.mutateAsync({ id: isCreating ? null : selectedId, draft });

      if (saved !== undefined && saved !== null) {
        setChosenId(saved.id);
      }

      setIsCreating(false);
      setDraft(null);
    } catch (cause) {
      setError(messageFor(cause, t('budgets.errors.save')));
    }
  };

  const commitAllocations = async () => {
    if (selectedId === null || allocations === null) {
      return;
    }

    setError(null);

    try {
      await saveAllocations.mutateAsync({ id: selectedId, allocations });
      setAllocations(null);
    } catch (cause) {
      setError(messageFor(cause, t('budgets.errors.allocations')));
    }
  };

  const act = async (action: () => Promise<unknown>, fallback: string) => {
    setError(null);

    try {
      await action();
    } catch (cause) {
      setError(messageFor(cause, fallback));
    }
  };

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1">
          <h1 className="text-content text-2xl font-semibold">{t('mediaPlanner.title')}</h1>
          <p className="text-content-secondary text-sm">{t('mediaPlanner.subtitle')}</p>
        </div>
        {canManage ? (
          <Button type="button" onClick={startDraft}>
            <Plus className="size-4" aria-hidden="true" />
            {t('mediaPlanner.newBudget')}
          </Button>
        ) : null}
      </header>

      {error === null ? null : <Alert tone="danger">{error}</Alert>}

      {alerts.data !== undefined && alerts.data.length > 0 ? (
        <Card className="flex flex-col gap-2 p-4">
          <h2 className="text-content text-base font-semibold">{t('mediaPlanner.alerts.title')}</h2>
          <ul className="flex flex-col gap-1">
            {alerts.data.map((alert) => (
              <li key={alert.budget.id} className="flex flex-wrap items-center gap-2 text-sm">
                <button
                  type="button"
                  className="text-content-brand underline-offset-2 hover:underline"
                  onClick={() => {
                    setIsCreating(false);
                    setDraft(null);
                    setChosenId(alert.budget.id);
                  }}
                >
                  {alert.budget.name}
                </button>
                <span className="text-content-tertiary">{alert.budget.client_name}</span>
                {alert.pacing === null ? (
                  <Badge tone="warning">{t('budgets.pacing.notPaced')}</Badge>
                ) : (
                  <Badge tone={alert.pacing.status === 'underspending' ? 'warning' : 'danger'}>
                    {alert.pacing.status_label}
                  </Badge>
                )}
                {/* Why it cannot be judged, where that is the answer. */}
                {alert.spend.unavailable_reason === null ? null : (
                  <span className="text-content-tertiary text-xs">
                    {alert.spend.unavailable_reason}
                  </span>
                )}
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      {budgets.isPending ? (
        <Skeleton className="h-10" />
      ) : budgets.data !== undefined && budgets.data.length > 0 ? (
        <nav aria-label={t('mediaPlanner.picker')} className="flex flex-wrap gap-1.5">
          {budgets.data.map((item) => (
            <Choice
              key={item.id}
              isSelected={item.id === selectedId}
              onSelect={() => {
                setIsCreating(false);
                setDraft(null);
                setAllocations(null);
                setChosenId(item.id);
              }}
            >
              <span className="flex items-center gap-1.5">
                {item.name}
                <Badge tone={STATUS_TONE[item.status]}>{item.status_label}</Badge>
              </span>
            </Choice>
          ))}
        </nav>
      ) : null}

      {draft !== null ? (
        <BudgetForm
          draft={draft}
          isCreating={isCreating}
          locale={locale}
          onChange={setDraft}
          onCancel={cancel}
          onSave={() => void commit()}
          isSaving={saveBudget.isPending}
        />
      ) : null}

      {draft === null && budgets.data !== undefined && budgets.data.length === 0 ? (
        <EmptyState
          title={t('mediaPlanner.empty.title')}
          description={t('mediaPlanner.empty.description')}
          action={
            canManage ? (
              <Button type="button" onClick={startDraft}>
                {t('mediaPlanner.newBudget')}
              </Button>
            ) : undefined
          }
        />
      ) : null}

      {draft === null && budget !== null ? (
        <div className="flex flex-col gap-4">
          <Card className="flex flex-col gap-3 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="flex flex-col gap-1">
                <h2 className="text-content text-lg font-semibold">{budget.name}</h2>
                <p className="text-content-secondary text-sm">
                  {t('mediaPlanner.detail.scope', {
                    client: budget.client_name,
                    project: budget.project_name ?? t('mediaPlanner.detail.wholeClient'),
                  })}
                </p>
                <p className="text-content-tertiary text-xs">
                  {t('mediaPlanner.detail.period', {
                    from: budget.period_start,
                    to: budget.period_end,
                    amount: formatCurrency(budget.amount_minor / 100, budget.currency, locale),
                  })}
                </p>
                {budget.approved_by_name === null ? null : (
                  <p className="text-content-tertiary text-xs">
                    {t('mediaPlanner.detail.approvedBy', { name: budget.approved_by_name })}
                  </p>
                )}
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <Badge tone={STATUS_TONE[budget.status]}>{budget.status_label}</Badge>
                {canManage && budget.allocations_are_editable ? (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => editBudget(budget)}
                  >
                    {t('common.edit')}
                  </Button>
                ) : null}
                {canApprove && budget.status === 'draft' ? (
                  <Button
                    type="button"
                    size="sm"
                    onClick={() =>
                      void act(() => approve.mutateAsync(budget.id), t('budgets.errors.approve'))
                    }
                  >
                    <Check className="size-4" aria-hidden="true" />
                    {t('mediaPlanner.approve')}
                  </Button>
                ) : null}
                {canManage && budget.status === 'approved' ? (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                      void act(() => archive.mutateAsync(budget.id), t('budgets.errors.archive'))
                    }
                  >
                    {t('mediaPlanner.archive')}
                  </Button>
                ) : null}
                {canManage && budget.status === 'draft' ? (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                      void act(async () => {
                        await remove.mutateAsync(budget.id);
                        setChosenId(null);
                      }, t('budgets.errors.delete'))
                    }
                  >
                    <Trash2 className="size-4" aria-hidden="true" />
                    {t('common.delete')}
                  </Button>
                ) : null}
              </div>
            </div>

            {/* Said once, where the control that would be refused used to be. */}
            {budget.status === 'approved' ? (
              <p className="text-content-tertiary text-xs">
                {t('mediaPlanner.detail.amountFixed')}
              </p>
            ) : null}
          </Card>

          <Card className="flex flex-col gap-4 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h3 className="text-content text-base font-semibold">
                {t('budgets.allocations.title')}
              </h3>
              {canManage && budget.allocations_are_editable ? (
                allocations === null ? (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => setAllocations(budget.allocations.map(toDraftNode))}
                  >
                    {t('budgets.allocations.edit')}
                  </Button>
                ) : (
                  <div className="flex gap-2">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => setAllocations(null)}
                    >
                      <X className="size-4" aria-hidden="true" />
                      {t('common.cancel')}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      onClick={() => void commitAllocations()}
                      disabled={saveAllocations.isPending}
                    >
                      <Save className="size-4" aria-hidden="true" />
                      {t('budgets.allocations.save')}
                    </Button>
                  </div>
                )
              ) : null}
            </div>

            {allocations === null ? (
              <AllocationSummary budget={budget} locale={locale} />
            ) : (
              <AllocationEditor
                allocations={allocations}
                budgetMinor={budget.amount_minor}
                currency={budget.currency}
                locale={locale}
                disabled={saveAllocations.isPending}
                onChange={setAllocations}
              />
            )}
          </Card>

          {pacing.isPending ? (
            <Skeleton className="h-64" />
          ) : pacing.data === undefined ? null : (
            <PacingPanel pacing={pacing.data} />
          )}
        </div>
      ) : null}
    </div>
  );
}

function AllocationSummary({ budget, locale }: { budget: BudgetPayload; locale: string }) {
  const { t } = useTranslation();

  if (budget.allocations.length === 0) {
    return <p className="text-content-tertiary text-sm">{t('budgets.allocations.none')}</p>;
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-content-secondary text-sm">
        {t('budgets.allocations.allocatedOf', {
          allocated: formatCurrency(budget.allocated_minor / 100, budget.currency, locale),
          budget: formatCurrency(budget.amount_minor / 100, budget.currency, locale),
        })}
      </p>
      <ul className="flex flex-col gap-2">
        {budget.allocations.map((allocation) => (
          <SummaryNode key={allocation.id} allocation={allocation} locale={locale} depth={0} />
        ))}
      </ul>
    </div>
  );
}

function SummaryNode({
  allocation,
  locale,
  depth,
}: {
  allocation: AllocationPayload;
  locale: string;
  depth: number;
}) {
  return (
    <li className={cn(depth > 0 && 'ms-4')}>
      <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
        <span className="text-content">{allocation.label}</span>
        <span className="text-content-secondary tabular-nums">
          {formatCurrency(allocation.amount_minor / 100, allocation.currency, locale)}
        </span>
      </div>
      {allocation.children.length === 0 ? null : (
        <ul className="mt-2 flex flex-col gap-2">
          {allocation.children.map((child) => (
            <SummaryNode key={child.id} allocation={child} locale={locale} depth={depth + 1} />
          ))}
        </ul>
      )}
    </li>
  );
}

function BudgetForm({
  draft,
  isCreating,
  locale,
  onChange,
  onCancel,
  onSave,
  isSaving,
}: {
  draft: BudgetDraft;
  isCreating: boolean;
  locale: string;
  onChange: (draft: BudgetDraft) => void;
  onCancel: () => void;
  onSave: () => void;
  isSaving: boolean;
}) {
  const { t } = useTranslation();
  const clients = useClientsQuery({ page: 1, per_page: 100 });

  return (
    <Card className="flex flex-col gap-4 p-4">
      <h2 className="text-content text-base font-semibold">
        {isCreating ? t('mediaPlanner.form.create') : t('mediaPlanner.form.edit')}
      </h2>

      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t('mediaPlanner.form.name')}>
          {(field) => (
            <Input
              {...field}
              value={draft.name}
              onChange={(event) => onChange({ ...draft, name: event.target.value })}
            />
          )}
        </Field>

        <Field label={t('mediaPlanner.form.amount', { currency: draft.currency })}>
          {(field) => (
            <Input
              {...field}
              inputMode="decimal"
              value={draft.amount}
              onChange={(event) => onChange({ ...draft, amount: event.target.value })}
            />
          )}
        </Field>

        <Field label={t('mediaPlanner.form.periodStart')}>
          {(field) => (
            <Input
              {...field}
              type="date"
              value={draft.period_start}
              onChange={(event) => onChange({ ...draft, period_start: event.target.value })}
            />
          )}
        </Field>

        <Field label={t('mediaPlanner.form.periodEnd')}>
          {(field) => (
            <Input
              {...field}
              type="date"
              value={draft.period_end}
              onChange={(event) => onChange({ ...draft, period_end: event.target.value })}
            />
          )}
        </Field>
      </div>

      <ChoiceGroup label={t('mediaPlanner.form.client')} hint={t('mediaPlanner.form.clientHint')}>
        {clients.isPending ? (
          <Skeleton className="h-8" />
        ) : (
          <div className="flex flex-wrap gap-1.5">
            {(clients.data?.data ?? []).map((client) => (
              <Choice
                key={client.id}
                isSelected={draft.client_id === client.id}
                onSelect={() => onChange({ ...draft, client_id: client.id })}
              >
                {client.name}
              </Choice>
            ))}
          </div>
        )}
      </ChoiceGroup>

      <Field label={t('mediaPlanner.form.notes')} hint={t('mediaPlanner.form.notesHint')}>
        {(field) => (
          <Textarea
            {...field}
            rows={2}
            value={draft.notes ?? ''}
            onChange={(event) => onChange({ ...draft, notes: event.target.value || null })}
          />
        )}
      </Field>

      <div className="flex flex-wrap items-center gap-2">
        <Button type="button" onClick={onSave} disabled={isSaving || draft.client_id === ''}>
          <Save className="size-4" aria-hidden="true" />
          {t('mediaPlanner.form.save')}
        </Button>
        <Button type="button" variant="ghost" onClick={onCancel}>
          <X className="size-4" aria-hidden="true" />
          {t('common.cancel')}
        </Button>
        <p className="text-content-tertiary text-xs">
          {t('mediaPlanner.form.notApprovedYet', {
            amount: formatCurrency(Number(draft.amount || 0), draft.currency, locale),
          })}
        </p>
      </div>
    </Card>
  );
}

/** A saved allocation as an editable draft. Server ids become session keys. */
function toDraftNode(allocation: AllocationPayload): AllocationDraft {
  return {
    key: allocation.id,
    level: allocation.level,
    provider: allocation.provider,
    label: allocation.label,
    amount: toDecimal(allocation.amount_minor),
    campaign_id: allocation.campaign_id,
    ad_group_id: allocation.ad_group_id,
    children: allocation.children.map(toDraftNode),
  };
}

/** A month starting today, which is the commonest thing somebody is about to type. */
function emptyDraft(): BudgetDraft {
  const start = new Date();
  const end = new Date(start);
  end.setMonth(end.getMonth() + 1);
  end.setDate(end.getDate() - 1);

  return {
    client_id: '',
    project_id: null,
    name: '',
    period_start: start.toISOString().slice(0, 10),
    period_end: end.toISOString().slice(0, 10),
    amount: '',
    currency: 'SAR',
    notes: null,
  };
}

/**
 * The server's own message when it has one.
 *
 * A budget refusal explains what to do instead — "archive it and approve a new one" —
 * and replacing that with a generic failure would throw away the only useful part.
 */
function messageFor(cause: unknown, fallback: string): string {
  if (cause instanceof ApiError && cause.message !== '') {
    return cause.message;
  }

  return fallback;
}
