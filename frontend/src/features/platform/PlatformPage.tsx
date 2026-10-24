import { useState, type FormEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { formatNumber, localeTag } from '@/shared/lib/format';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { Logo } from '@/shared/ui/logo';
import { Skeleton } from '@/shared/ui/skeleton';
import {
  useChangePlanMutation,
  useChangeStatusMutation,
  useCreateWorkspaceMutation,
  useOverrideMutation,
  usePlatformWorkspaceQuery,
  usePlatformWorkspacesQuery,
  type NewWorkspaceDraft,
  type PlatformWorkspace,
} from './api';

const STATUSES = ['trial', 'active', 'past_due', 'grace', 'suspended', 'cancelled'] as const;
const PLANS = ['starter', 'growth', 'agency', 'enterprise'] as const;
const TYPES = ['agency', 'brand', 'freelancer', 'internal_team'] as const;

const selectClass =
  'border-line bg-surface text-content h-11 w-full rounded-lg border px-3 text-base sm:text-sm focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none';

function failure(error: unknown, fallback: string): string {
  return error instanceof ApiError && error.message !== '' ? error.message : fallback;
}

function Select({
  label,
  value,
  onChange,
  options,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: ReadonlyArray<{ value: string; label: string }>;
}) {
  return (
    <Field label={label}>
      {(props) => (
        <select
          id={props.id}
          aria-describedby={props['aria-describedby']}
          className={selectClass}
          value={value}
          onChange={(event) => onChange(event.target.value)}
        >
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      )}
    </Field>
  );
}

function Text({
  label,
  value,
  onChange,
  type = 'text',
  required = false,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  type?: string;
  required?: boolean;
}) {
  return (
    <Field label={label} required={required}>
      {(props) => (
        <Input
          {...props}
          type={type}
          value={value}
          required={required}
          onChange={(event) => onChange(event.target.value)}
        />
      )}
    </Field>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="border-border bg-surface-raised flex flex-col gap-3 rounded-2xl border p-5">
      <h2 className="text-content text-base font-semibold">{title}</h2>
      {children}
    </section>
  );
}

/**
 * The platform console: every tenant as counts and states, and the few acts an operator may take on one — its plan, an override,
 * its subscription state — each with a reason, and creating a new workspace with its owner.
 *
 * Nothing here shows a client's name, a figure or a credential, and nothing here opens a workspace: an operator runs tenants from
 * outside them.
 */
export function PlatformPage() {
  const { t } = useTranslation();
  const { user } = useSession();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [selected, setSelected] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const list = usePlatformWorkspacesQuery({ search, status });

  if (user?.is_platform_operator !== true) {
    return (
      <main className="bg-canvas min-h-dvh p-6">
        <Alert tone="warning">{t('platform.forbidden')}</Alert>
      </main>
    );
  }

  return (
    <div className="bg-canvas min-h-dvh">
      <header className="border-line-subtle flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 sm:px-6">
        <div className="flex items-center gap-3">
          <Logo width={132} />
          <Badge tone="accent">{t('platform.title')}</Badge>
        </div>
        {user.active_workspace !== null ? (
          <Link
            to="/"
            className="text-content-brand text-sm font-medium underline-offset-4 hover:underline"
          >
            {t('platform.backToWorkspace', { name: user.active_workspace.name })}
          </Link>
        ) : null}
      </header>

      <main id="main" className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-6 sm:px-6">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 className="text-content text-2xl font-semibold">{t('platform.workspaces')}</h1>
            <p className="text-content-secondary text-sm">{t('platform.subtitle')}</p>
          </div>
          <Button onClick={() => setCreating((open) => !open)}>{t('platform.create.open')}</Button>
        </div>

        {creating ? <CreateWorkspace onDone={(id) => setSelected(id)} /> : null}

        <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_14rem]">
          <Text label={t('platform.search')} value={search} onChange={setSearch} />
          <Select
            label={t('platform.statusFilter')}
            value={status}
            onChange={setStatus}
            options={[
              { value: '', label: t('platform.anyStatus') },
              ...STATUSES.map((s) => ({ value: s, label: t(`platform.status.${s}`) })),
            ]}
          />
        </div>

        {list.isError ? <Alert tone="danger">{t('platform.loadFailed')}</Alert> : null}
        {list.isPending ? <Skeleton className="h-48 w-full" /> : null}

        {list.data !== undefined ? (
          <ul aria-label={t('platform.workspaces')} className="grid gap-3 lg:grid-cols-2">
            {list.data.data.map((workspace) => (
              <WorkspaceCard
                key={workspace.id}
                workspace={workspace}
                selected={selected === workspace.id}
                onSelect={() => setSelected(selected === workspace.id ? null : workspace.id)}
              />
            ))}
            {list.data.data.length === 0 ? (
              <li className="text-content-secondary text-sm">{t('platform.none')}</li>
            ) : null}
          </ul>
        ) : null}

        {selected !== null ? <WorkspaceDetail id={selected} /> : null}
      </main>
    </div>
  );
}

function WorkspaceCard({
  workspace,
  selected,
  onSelect,
}: {
  workspace: PlatformWorkspace;
  selected: boolean;
  onSelect: () => void;
}) {
  const { t, i18n } = useTranslation();
  const stopped = workspace.status === 'suspended' || workspace.status === 'cancelled';
  const connected = Object.values(workspace.connections).reduce((sum, n) => sum + n, 0);

  return (
    <li>
      <article
        aria-label={workspace.name}
        className="border-border bg-surface-raised flex flex-col gap-3 rounded-2xl border p-4"
      >
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div className="min-w-0">
            <h2 className="text-content truncate text-base font-semibold">{workspace.name}</h2>
            <p className="text-content-tertiary text-xs">
              {t(`workspace.type.${workspace.type}`)} · {workspace.slug}
            </p>
          </div>
          <div className="flex flex-wrap gap-1.5">
            <Badge tone="neutral">{t(`platform.plan.${workspace.plan}`, workspace.plan)}</Badge>
            <Badge
              tone={stopped ? 'danger' : workspace.status === 'active' ? 'success' : 'warning'}
            >
              {t(`platform.status.${workspace.status}`)}
            </Badge>
            {workspace.provisioning === 'incomplete' ? (
              <Badge tone="danger">
                {t('platform.incomplete', { missing: workspace.missing.join(', ') })}
              </Badge>
            ) : null}
          </div>
        </div>
        <dl className="text-content-secondary grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:grid-cols-3">
          {(
            [
              ['seatsInternal', workspace.seats.internal],
              ['seatsClient', workspace.seats.client],
              ['clients', workspace.clients],
              ['projects', workspace.projects],
              ['connections', connected],
              ['overrides', workspace.overrides],
            ] as const
          ).map(([key, value]) => (
            <div key={key} className="flex justify-between gap-2">
              <dt>{t(`platform.count.${key}`)}</dt>
              <dd className="text-content tabular-nums">{formatNumber(value, i18n.language)}</dd>
            </div>
          ))}
        </dl>
        {workspace.trial_ends_at !== null ? (
          <p className="text-content-tertiary text-xs">
            {t('platform.trialEnds', {
              date: new Date(workspace.trial_ends_at).toLocaleDateString(localeTag(i18n.language)),
            })}
          </p>
        ) : null}
        <Button
          variant="secondary"
          aria-expanded={selected}
          onClick={onSelect}
          className="self-start"
        >
          {selected ? t('platform.close') : t('platform.manage')}
        </Button>
      </article>
    </li>
  );
}

function WorkspaceDetail({ id }: { id: string }) {
  const { t, i18n } = useTranslation();
  const detail = usePlatformWorkspaceQuery(id);
  const changePlan = useChangePlanMutation(id);
  const changeStatus = useChangeStatusMutation(id);
  const override = useOverrideMutation(id);
  const [plan, setPlan] = useState('');
  const [planReason, setPlanReason] = useState('');
  const [nextStatus, setNextStatus] = useState('');
  const [statusReason, setStatusReason] = useState('');
  const [kind, setKind] = useState('limit');
  const [key, setKey] = useState('');
  const [value, setValue] = useState('');
  const [overrideReason, setOverrideReason] = useState('');

  if (detail.isPending) {
    return <Skeleton className="h-64 w-full" />;
  }

  if (detail.isError) {
    return <Alert tone="danger">{t('platform.loadFailed')}</Alert>;
  }

  const data = detail.data;
  const keys =
    kind === 'feature'
      ? data.plan_detail.features.filter((f) => !f.everywhere).map((f) => f.key)
      : data.plan_detail.limits.map((l) => l.key);

  return (
    <div
      aria-label={t('platform.detailFor', { name: data.name })}
      role="region"
      className="flex flex-col gap-4"
    >
      <h2 className="text-content text-xl font-semibold">{data.name}</h2>
      {data.status_reason !== null ? (
        <p className="text-content-secondary text-sm">
          {t('platform.reasonLine', { reason: data.status_reason })}
        </p>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-3">
        <Section title={t('platform.statusTitle')}>
          <form
            className="flex flex-col gap-3"
            onSubmit={(event: FormEvent) => {
              event.preventDefault();
              changeStatus.mutate(
                { status: nextStatus || data.status, reason: statusReason },
                { onSuccess: () => setStatusReason('') },
              );
            }}
          >
            <Select
              label={t('platform.newStatus')}
              value={nextStatus || data.status}
              onChange={setNextStatus}
              options={STATUSES.map((s) => ({ value: s, label: t(`platform.status.${s}`) }))}
            />
            <Text
              label={t('platform.reason')}
              value={statusReason}
              onChange={setStatusReason}
              required
            />
            <p className="text-content-tertiary text-xs">{t('platform.statusHint')}</p>
            {changeStatus.isError ? (
              <Alert tone="danger">{failure(changeStatus.error, t('platform.actionFailed'))}</Alert>
            ) : null}
            <Button type="submit" disabled={statusReason.trim() === '' || changeStatus.isPending}>
              {t('platform.saveStatus')}
            </Button>
          </form>
        </Section>

        <Section title={t('platform.planTitle')}>
          <form
            className="flex flex-col gap-3"
            onSubmit={(event: FormEvent) => {
              event.preventDefault();
              changePlan.mutate(
                { plan: plan || data.plan, reason: planReason },
                { onSuccess: () => setPlanReason('') },
              );
            }}
          >
            <Select
              label={t('platform.newPlan')}
              value={plan || data.plan}
              onChange={setPlan}
              options={PLANS.map((p) => ({ value: p, label: t(`platform.plan.${p}`) }))}
            />
            <Text
              label={t('platform.reason')}
              value={planReason}
              onChange={setPlanReason}
              required
            />
            <p className="text-content-tertiary text-xs">{t('platform.planHint')}</p>
            {changePlan.isError ? (
              <Alert tone="danger">{failure(changePlan.error, t('platform.actionFailed'))}</Alert>
            ) : null}
            <Button type="submit" disabled={planReason.trim() === '' || changePlan.isPending}>
              {t('platform.savePlan')}
            </Button>
          </form>
        </Section>

        <Section title={t('platform.overrideTitle')}>
          <form
            className="flex flex-col gap-3"
            onSubmit={(event: FormEvent) => {
              event.preventDefault();
              override.mutate(
                { kind, key: key || (keys[0] ?? ''), value, reason: overrideReason },
                {
                  onSuccess: () => {
                    setValue('');
                    setOverrideReason('');
                  },
                },
              );
            }}
          >
            <Select
              label={t('platform.kind')}
              value={kind}
              onChange={(next) => {
                setKind(next);
                setKey('');
              }}
              options={[
                { value: 'limit', label: t('platform.kindLimit') },
                { value: 'feature', label: t('platform.kindFeature') },
              ]}
            />
            <Select
              label={t('platform.key')}
              value={key || (keys[0] ?? '')}
              onChange={setKey}
              options={keys.map((k) => ({
                value: k,
                label: t(`plan.${kind === 'feature' ? 'feature' : 'limit'}.${k}`),
              }))}
            />
            <Text
              label={t(kind === 'feature' ? 'platform.valueFeature' : 'platform.valueLimit')}
              value={value}
              onChange={setValue}
              required
            />
            <Text
              label={t('platform.reason')}
              value={overrideReason}
              onChange={setOverrideReason}
              required
            />
            {override.isError ? (
              <Alert tone="danger">{failure(override.error, t('platform.actionFailed'))}</Alert>
            ) : null}
            <Button
              type="submit"
              disabled={value.trim() === '' || overrideReason.trim() === '' || override.isPending}
            >
              {t('platform.saveOverride')}
            </Button>
          </form>
        </Section>
      </div>

      <Section title={t('plan.limitsTitle')}>
        <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          {data.plan_detail.limits.map((limit) => (
            <li
              key={limit.key}
              className="border-border flex justify-between gap-2 rounded-xl border px-3 py-2 text-sm"
            >
              <span className="text-content">{t(`plan.limit.${limit.key}`)}</span>
              <span
                className={
                  limit.over ? 'text-danger tabular-nums' : 'text-content-secondary tabular-nums'
                }
              >
                {limit.used === null
                  ? t('plan.notMeasured')
                  : limit.allowed === null
                    ? t('plan.usedUnlimited', { used: formatNumber(limit.used, i18n.language) })
                    : t('plan.usedOf', {
                        used: formatNumber(limit.used, i18n.language),
                        allowed: formatNumber(limit.allowed, i18n.language),
                      })}
              </span>
            </li>
          ))}
        </ul>
      </Section>

      <Section title={t('platform.history')}>
        {data.history.length === 0 ? (
          <p className="text-content-secondary text-sm">{t('platform.noHistory')}</p>
        ) : (
          <ol className="flex flex-col gap-2">
            {data.history.map((entry) => (
              <li key={`${entry.at}-${entry.action}`} className="text-sm">
                <span className="text-content-tertiary tabular-nums">
                  {new Date(entry.at).toLocaleString(localeTag(i18n.language))}
                </span>{' '}
                <span className="text-content">{entry.summary}</span>
              </li>
            ))}
          </ol>
        )}
      </Section>
    </div>
  );
}

function CreateWorkspace({ onDone }: { onDone: (id: string) => void }) {
  const { t } = useTranslation();
  const create = useCreateWorkspaceMutation();
  const [draft, setDraft] = useState<NewWorkspaceDraft>({
    name: '',
    slug: '',
    type: 'agency',
    locale: 'ar',
    timezone: 'Asia/Riyadh',
    currency: 'SAR',
    plan: 'agency',
    status: 'trial',
    trial_days: 14,
    owner_name: '',
    owner_email: '',
  });
  const set =
    <K extends keyof NewWorkspaceDraft>(field: K) =>
    (value: NewWorkspaceDraft[K]) =>
      setDraft((current) => ({ ...current, [field]: value }));

  return (
    <Section title={t('platform.create.title')}>
      <p className="text-content-secondary text-sm">{t('platform.create.hint')}</p>
      <form
        className="grid gap-3 sm:grid-cols-2"
        onSubmit={(event: FormEvent) => {
          event.preventDefault();
          create.mutate(draft, { onSuccess: (result) => onDone(result.workspace_id) });
        }}
      >
        <Text
          label={t('platform.create.name')}
          value={draft.name}
          onChange={set('name')}
          required
        />
        <Text label={t('platform.create.slug')} value={draft.slug} onChange={set('slug')} />
        <Select
          label={t('platform.create.type')}
          value={draft.type}
          onChange={set('type')}
          options={TYPES.map((v) => ({ value: v, label: t(`workspace.type.${v}`) }))}
        />
        <Select
          label={t('platform.create.locale')}
          value={draft.locale}
          onChange={set('locale')}
          options={[
            { value: 'ar', label: 'العربية' },
            { value: 'en', label: 'English' },
          ]}
        />
        <Text
          label={t('platform.create.timezone')}
          value={draft.timezone}
          onChange={set('timezone')}
          required
        />
        <Text
          label={t('platform.create.currency')}
          value={draft.currency}
          onChange={set('currency')}
          required
        />
        <Select
          label={t('platform.newPlan')}
          value={draft.plan}
          onChange={set('plan')}
          options={PLANS.map((p) => ({ value: p, label: t(`platform.plan.${p}`) }))}
        />
        <Select
          label={t('platform.create.start')}
          value={draft.status}
          onChange={(value) => set('status')(value === 'active' ? 'active' : 'trial')}
          options={[
            { value: 'trial', label: t('platform.status.trial') },
            { value: 'active', label: t('platform.status.active') },
          ]}
        />
        {draft.status === 'trial' ? (
          <Text
            label={t('platform.create.trialDays')}
            type="number"
            value={String(draft.trial_days)}
            onChange={(value) => set('trial_days')(Number(value) || 14)}
          />
        ) : null}
        <Text
          label={t('platform.create.ownerName')}
          value={draft.owner_name}
          onChange={set('owner_name')}
          required
        />
        <Text
          label={t('platform.create.ownerEmail')}
          type="email"
          value={draft.owner_email}
          onChange={set('owner_email')}
          required
        />
        <div className="flex flex-col gap-3 sm:col-span-2">
          {create.isError ? (
            <Alert tone="danger">{failure(create.error, t('platform.actionFailed'))}</Alert>
          ) : null}
          {create.data !== undefined ? (
            <Alert tone="info">
              {create.data.one_time_password === null
                ? t('platform.create.doneExisting', { email: create.data.owner_email })
                : t('platform.create.doneNew', { email: create.data.owner_email })}
              {create.data.one_time_password !== null ? (
                <code
                  data-testid="one-time-password"
                  className="bg-surface-sunken ms-2 rounded px-2 py-0.5 font-mono"
                >
                  {create.data.one_time_password}
                </code>
              ) : null}
            </Alert>
          ) : null}
          <Button type="submit" className="self-start" disabled={create.isPending}>
            {t('platform.create.submit')}
          </Button>
        </div>
      </form>
    </Section>
  );
}
