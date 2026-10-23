import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { ProjectScope } from '../health/ProjectScope';
import { useFollowUpQuery, useSaveAutomationMutation } from './api';
import type {
  AssignmentMode,
  AutomationSettings,
  FollowUp,
  QueueGroupKey,
  QueueItem,
  SlaStateValue,
} from './types';

const MODES: AssignmentMode[] = ['none', 'specific', 'project_default', 'round_robin'];
const GROUPS: QueueGroupKey[] = [
  'overdue',
  'approaching',
  'new',
  'attempted',
  'no_response',
  'qualified',
];
const SLA_PRESETS = [5, 10, 30, 60];
const SELECT =
  'border-line bg-surface text-content h-11 w-full min-w-0 truncate rounded-lg border px-3 text-[16px] md:h-10 md:text-sm';

const TONE: Record<SlaStateValue, 'success' | 'warning' | 'danger' | 'neutral'> = {
  within: 'success',
  approaching: 'warning',
  overdue: 'danger',
  attempted: 'neutral',
  contacted: 'success',
};

/** The state is a word before it is a colour: nothing on this page is carried by colour alone. */
function SlaBadge({ state }: { state: SlaStateValue | null }) {
  const { t } = useTranslation();

  return state === null ? null : (
    <Badge tone={TONE[state]}>{t(`tracking.followUp.sla.${state}`)}</Badge>
  );
}

function Item({ item, zone }: { item: QueueItem; zone: string }) {
  const { t, i18n } = useTranslation();
  const when = (iso: string): string =>
    new Intl.DateTimeFormat(i18n.language, {
      dateStyle: 'short',
      timeStyle: 'short',
      timeZone: zone,
    }).format(new Date(iso));

  return (
    <li className="bg-surface-sunken flex min-w-0 flex-col gap-1.5 rounded-2xl p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-content min-w-0 truncate text-sm font-medium">
          {item.name ?? t('tracking.followUp.unnamed')}
        </p>
        <SlaBadge state={item.sla_state} />
      </div>
      <p className="text-content-secondary text-xs">
        {item.source ?? t('tracking.followUp.noSource')} ·{' '}
        {t('tracking.followUp.arrived', { when: when(item.received_at) })}
      </p>
      <p className="text-content-tertiary text-xs">
        {item.owner === null
          ? t('tracking.followUp.unassigned')
          : t('tracking.followUp.heldBy', { name: item.owner.name ?? '' })}
        {item.assignment_reason
          ? ` · ${t(`tracking.followUp.reasons.${item.assignment_reason}`, { defaultValue: item.assignment_reason })}`
          : ''}
        {item.due_at && item.sla_state !== 'contacted' && item.sla_state !== 'attempted'
          ? ` · ${t('tracking.followUp.dueBy', { when: when(item.due_at) })}`
          : ''}
      </p>
    </li>
  );
}

function Queue({ data }: { data: FollowUp }) {
  const { t } = useTranslation();
  const total = GROUPS.reduce((n, g) => n + data.groups[g].count, 0);

  if (total === 0) {
    return (
      <EmptyState
        title={t('tracking.followUp.emptyTitle')}
        description={t('tracking.followUp.emptyBody')}
      />
    );
  }

  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      {GROUPS.filter((g) => data.groups[g].count > 0).map((g) => (
        <Card key={g} className="flex min-w-0 flex-col gap-3 p-4 sm:p-5">
          <h3 className="text-content flex items-center justify-between gap-2 text-sm font-semibold">
            {t(`tracking.followUp.groups.${g}`)}
            <Badge tone={g === 'overdue' ? 'danger' : g === 'approaching' ? 'warning' : 'neutral'}>
              {data.groups[g].count}
            </Badge>
          </h3>
          <ul className="flex flex-col gap-2">
            {data.groups[g].items.map((item) => (
              <Item key={item.id} item={item} zone={data.timezone} />
            ))}
          </ul>
          {data.groups[g].count > data.groups[g].items.length ? (
            <p className="text-content-tertiary text-xs">
              {t('tracking.followUp.more', {
                count: data.groups[g].count - data.groups[g].items.length,
              })}
            </p>
          ) : null}
        </Card>
      ))}
    </div>
  );
}

function Settings({ data, canManage }: { data: FollowUp; canManage: boolean }) {
  const { t } = useTranslation();
  const save = useSaveAutomationMutation();
  const [draft, setDraft] = useState<AutomationSettings>(data.settings);
  const set = <K extends keyof AutomationSettings>(key: K, value: AutomationSettings[K]) =>
    setDraft((d) => ({ ...d, [key]: value }));
  const needsOwner =
    draft.assignment_mode === 'specific' || draft.assignment_mode === 'project_default';

  return (
    <Card className="flex min-w-0 flex-col gap-4 p-4 sm:p-5">
      <h2 className="text-content text-base font-semibold">
        {t('tracking.followUp.settings.title')}
      </h2>
      <p className="text-content-secondary text-sm">{t('tracking.followUp.settings.body')}</p>
      <form
        className="flex flex-col gap-4"
        onSubmit={(event) => {
          event.preventDefault();
          save.mutate({ ...draft, owner_id: needsOwner ? draft.owner_id : null });
        }}
      >
        <label className="flex min-h-11 items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="size-4"
            checked={draft.enabled}
            disabled={!canManage}
            onChange={(e) => set('enabled', e.target.checked)}
          />
          {t('tracking.followUp.settings.enabled')}
        </label>

        <Field
          label={t('tracking.followUp.settings.assignment')}
          hint={t('tracking.followUp.settings.assignmentHint')}
        >
          {(f) => (
            <div className="-mx-1 min-w-0 overflow-x-clip px-1">
              <select
                {...f}
                className={SELECT}
                value={draft.assignment_mode}
                disabled={!canManage}
                onChange={(e) => set('assignment_mode', e.target.value as AssignmentMode)}
              >
                {MODES.map((m) => (
                  <option key={m} value={m}>
                    {t(`tracking.followUp.modes.${m}`)}
                  </option>
                ))}
              </select>
            </div>
          )}
        </Field>

        {needsOwner ? (
          <Field
            label={t('tracking.followUp.settings.owner')}
            hint={t('tracking.followUp.settings.ownerHint')}
          >
            {(f) => (
              <div className="-mx-1 min-w-0 overflow-x-clip px-1">
                <select
                  {...f}
                  className={SELECT}
                  value={draft.owner_id ?? ''}
                  disabled={!canManage}
                  onChange={(e) => set('owner_id', e.target.value === '' ? null : e.target.value)}
                >
                  <option value="">{t('tracking.followUp.settings.pickOwner')}</option>
                  {data.owners.map((o) => (
                    <option key={o.id} value={o.id}>
                      {o.name}
                    </option>
                  ))}
                </select>
              </div>
            )}
          </Field>
        ) : null}

        <Field
          label={t('tracking.followUp.settings.sla')}
          hint={t('tracking.followUp.settings.slaHint')}
        >
          {(f) => (
            <div className="flex flex-wrap items-center gap-2">
              {SLA_PRESETS.map((m) => (
                <Button
                  key={m}
                  type="button"
                  size="sm"
                  className="max-md:h-11"
                  variant={draft.sla_minutes === m ? 'primary' : 'secondary'}
                  disabled={!canManage}
                  onClick={() => set('sla_minutes', m)}
                >
                  {t('tracking.followUp.settings.minutes', { count: m })}
                </Button>
              ))}
              <Input
                {...f}
                type="number"
                inputMode="numeric"
                min={1}
                max={10080}
                className="w-28"
                value={draft.sla_minutes ?? ''}
                disabled={!canManage}
                placeholder={t('tracking.followUp.settings.noPromise')}
                onChange={(e) =>
                  set('sla_minutes', e.target.value === '' ? null : Number(e.target.value))
                }
              />
            </div>
          )}
        </Field>

        <label className="flex min-h-11 items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="size-4"
            checked={draft.create_task}
            disabled={!canManage}
            onChange={(e) => set('create_task', e.target.checked)}
          />
          {t('tracking.followUp.settings.createTask')}
        </label>
        {draft.create_task ? (
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label={t('tracking.followUp.settings.titleAr')}>
              {(f) => (
                <Input
                  {...f}
                  dir="rtl"
                  value={draft.task_title_ar ?? ''}
                  disabled={!canManage}
                  placeholder="اتصل بالعميل المحتمل"
                  onChange={(e) => set('task_title_ar', e.target.value || null)}
                />
              )}
            </Field>
            <Field label={t('tracking.followUp.settings.titleEn')}>
              {(f) => (
                <Input
                  {...f}
                  dir="ltr"
                  value={draft.task_title_en ?? ''}
                  disabled={!canManage}
                  placeholder="Call the new lead"
                  onChange={(e) => set('task_title_en', e.target.value || null)}
                />
              )}
            </Field>
          </div>
        ) : null}

        <label className="flex min-h-11 items-center gap-2 text-sm">
          <input
            type="checkbox"
            className="size-4"
            checked={draft.notify_owner}
            disabled={!canManage}
            onChange={(e) => set('notify_owner', e.target.checked)}
          />
          {t('tracking.followUp.settings.notify')}
        </label>

        {save.isError ? (
          <Alert tone="danger">
            {save.error instanceof ApiError && save.error.message !== ''
              ? save.error.message
              : t('tracking.followUp.settings.failed')}
          </Alert>
        ) : null}
        {save.isSuccess ? <Alert tone="info">{t('tracking.followUp.settings.saved')}</Alert> : null}

        {canManage ? (
          <div className="flex justify-end">
            <Button type="submit" disabled={save.isPending} className="max-md:h-11">
              {t('tracking.followUp.settings.save')}
            </Button>
          </div>
        ) : null}
      </form>
    </Card>
  );
}

/** What the team still has to answer, and what the project does about a new lead. */
export function FollowUpTab() {
  const { t } = useTranslation();
  const { can } = useSession();
  const [project, setProject] = useState<{ id: string; label: string } | null>(null);
  const followUp = useFollowUpQuery(project?.id ?? null);

  return (
    <div className="flex min-w-0 flex-col gap-5">
      <p className="text-content-secondary max-w-prose text-sm">{t('tracking.followUp.intro')}</p>
      <ProjectScope value={project} onChange={setProject} />
      {project === null ? (
        <EmptyState
          title={t('tracking.funnels.pick.title')}
          description={t('tracking.funnels.pick.body')}
        />
      ) : followUp.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : followUp.isError ? (
        <Alert tone="danger">{t('tracking.followUp.failed')}</Alert>
      ) : (
        <>
          <Queue data={followUp.data} />
          <Settings
            key={project.id}
            data={followUp.data}
            canManage={can(PERMISSIONS.trackingManage)}
          />
        </>
      )}
    </div>
  );
}
