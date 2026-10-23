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
import {
  useConversionsQuery,
  useDeleteMappingMutation,
  useRetryDispatchMutation,
  useSaveMappingMutation,
  useSavePolicyMutation,
} from './api';
import type { Dispatch, ProviderEntry } from './types';

const SELECT =
  'border-line bg-surface text-content h-11 w-full min-w-0 truncate rounded-lg border px-3 text-[16px] md:h-10 md:text-sm';

const STATUS_TONE: Record<
  Dispatch['status'],
  'success' | 'neutral' | 'warning' | 'danger' | 'info'
> = {
  sent: 'success',
  pending: 'info',
  skipped: 'neutral',
  failed: 'danger',
  unknown: 'warning',
};

function ProviderCard({ entry }: { entry: ProviderEntry }) {
  const { t } = useTranslation();

  return (
    <li className="bg-surface-sunken flex flex-col gap-1.5 rounded-2xl p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-content text-sm font-medium">{entry.label}</p>
        <Badge tone={entry.support === 'DOCUMENTED' ? 'success' : 'neutral'}>
          {t(`tracking.dispatch.support.${entry.support}`)}
        </Badge>
      </div>
      {entry.mechanism ? <p className="text-content-secondary text-xs">{entry.mechanism}</p> : null}
      {entry.event_rule ? (
        <p className="text-content-secondary text-xs">{entry.event_rule}</p>
      ) : null}
      {entry.dedup ? <p className="text-content-tertiary text-xs">{entry.dedup}</p> : null}
      {entry.caveat ? <p className="text-warning text-xs">{entry.caveat}</p> : null}
      {entry.source ? (
        <p className="text-content-tertiary text-xs" dir="ltr">
          <a href={entry.source} target="_blank" rel="noreferrer" className="underline">
            {t('tracking.dispatch.source')}
          </a>{' '}
          · {entry.read_on}
        </p>
      ) : null}
    </li>
  );
}

function MappingForm({
  projectId,
  events,
  providers,
}: {
  projectId: string;
  events: string[];
  providers: ProviderEntry[];
}) {
  const { t } = useTranslation();
  const save = useSaveMappingMutation();
  const [event, setEvent] = useState(events[0] ?? 'lead_created');
  const [provider, setProvider] = useState('');
  const [name, setName] = useState('');
  const entry = providers.find((p) => p.provider === provider);

  return (
    <form
      className="grid gap-3 sm:grid-cols-3"
      onSubmit={(e) => {
        e.preventDefault();
        save.mutate(
          { projectId, event, provider, providerEvent: name },
          { onSuccess: () => setName('') },
        );
      }}
    >
      <Field label={t('tracking.dispatch.form.event')}>
        {(f) => (
          <select
            {...f}
            className={SELECT}
            value={event}
            onChange={(e) => setEvent(e.target.value)}
          >
            {events.map((ev) => (
              <option key={ev} value={ev}>
                {t(`tracking.funnels.sources.${ev}`)}
              </option>
            ))}
          </select>
        )}
      </Field>
      <Field label={t('tracking.dispatch.form.provider')}>
        {(f) => (
          <select
            {...f}
            className={SELECT}
            value={provider}
            onChange={(e) => {
              setProvider(e.target.value);
              setName('');
            }}
          >
            <option value="">{t('tracking.events.test.choose')}</option>
            {providers.map((p) => (
              <option key={p.provider} value={p.provider} disabled={p.support !== 'DOCUMENTED'}>
                {p.label}
                {p.support === 'DOCUMENTED'
                  ? ''
                  : ` — ${t(`tracking.dispatch.support.${p.support}`)}`}
              </option>
            ))}
          </select>
        )}
      </Field>
      <Field
        label={t('tracking.dispatch.form.providerEvent')}
        hint={entry?.event_rule ?? undefined}
      >
        {(f) =>
          entry?.events ? (
            <select
              {...f}
              className={SELECT}
              value={name}
              onChange={(e) => setName(e.target.value)}
            >
              <option value="">{t('tracking.events.test.choose')}</option>
              {entry.events.map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          ) : (
            <Input
              {...f}
              dir="ltr"
              value={name}
              onChange={(e) => setName(e.target.value)}
              disabled={provider === ''}
            />
          )
        }
      </Field>
      {save.isError ? (
        <div className="sm:col-span-3">
          <Alert tone="danger">
            {save.error instanceof ApiError && save.error.message !== ''
              ? save.error.message
              : t('tracking.dispatch.form.failed')}
          </Alert>
        </div>
      ) : null}
      <div className="flex justify-end sm:col-span-3">
        <Button type="submit" disabled={save.isPending || provider === '' || name.trim() === ''}>
          {t('tracking.dispatch.form.save')}
        </Button>
      </div>
    </form>
  );
}

function PolicyForm({
  projectId,
  consent,
  retention,
}: {
  projectId: string;
  consent: string;
  retention: number;
}) {
  const { t } = useTranslation();
  const save = useSavePolicyMutation();
  const [policy, setPolicy] = useState(consent);
  const [days, setDays] = useState(String(retention));

  return (
    <form
      className="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]"
      onSubmit={(e) => {
        e.preventDefault();
        save.mutate({ projectId, consentPolicy: policy, retentionDays: Number(days) });
      }}
    >
      <Field
        label={t('tracking.dispatch.policy.consent')}
        hint={t(`tracking.dispatch.policy.hint.${policy}`)}
      >
        {(f) => (
          <select
            {...f}
            className={SELECT}
            value={policy}
            onChange={(e) => setPolicy(e.target.value)}
          >
            {['required', 'not_required'].map((p) => (
              <option key={p} value={p}>
                {t(`tracking.dispatch.policy.options.${p}`)}
              </option>
            ))}
          </select>
        )}
      </Field>
      <Field
        label={t('tracking.dispatch.policy.retention')}
        hint={t('tracking.dispatch.policy.retentionHint')}
      >
        {(f) => (
          <Input
            {...f}
            dir="ltr"
            type="number"
            inputMode="numeric"
            min={30}
            max={1095}
            value={days}
            onChange={(e) => setDays(e.target.value)}
          />
        )}
      </Field>
      <Button type="submit" disabled={save.isPending}>
        {t('tracking.dispatch.policy.save')}
      </Button>
      {save.isError ? (
        <div className="sm:col-span-3">
          <Alert tone="danger">
            {save.error instanceof ApiError && save.error.message !== ''
              ? save.error.message
              : t('tracking.dispatch.policy.failed')}
          </Alert>
        </div>
      ) : null}
    </form>
  );
}

/** Conversions: which provider event each digitalSmart event becomes, what happened to each one, and the policy around it. */
export function ConversionsTab() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const canManage = can(PERMISSIONS.trackingManage);
  const [project, setProject] = useState<{ id: string; label: string } | null>(null);
  const data = useConversionsQuery(project?.id ?? null);
  const remove = useDeleteMappingMutation();
  const retry = useRetryDispatchMutation();
  const when = new Intl.DateTimeFormat(
    i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : i18n.language,
    { dateStyle: 'short', timeStyle: 'short' },
  );
  const providerLabel = (slug: string) =>
    data.data?.providers.find((p) => p.provider === slug)?.label ?? slug;

  return (
    <div className="flex flex-col gap-5">
      <p className="text-content-secondary max-w-prose text-sm">{t('tracking.dispatch.intro')}</p>
      <ProjectScope value={project} onChange={setProject} />

      {project === null ? (
        <EmptyState
          title={t('tracking.funnels.pick.title')}
          description={t('tracking.funnels.pick.body')}
        />
      ) : data.isPending ? (
        <Skeleton className="h-48 w-full" />
      ) : data.isError ? (
        <Alert tone="danger">{t('tracking.funnels.failed')}</Alert>
      ) : (
        <>
          <Card className="flex flex-col gap-3 p-4 sm:p-5">
            <h2 className="text-content text-base font-semibold">
              {t('tracking.dispatch.providers')}
            </h2>
            <ul className="grid gap-2 lg:grid-cols-2">
              {data.data.providers.map((entry) => (
                <ProviderCard key={entry.provider} entry={entry} />
              ))}
            </ul>
          </Card>

          <Card className="flex flex-col gap-4 p-4 sm:p-5">
            <h2 className="text-content text-base font-semibold">
              {t('tracking.dispatch.mappings')}
            </h2>
            {data.data.mappings.length === 0 ? (
              <p className="text-content-secondary text-sm">{t('tracking.dispatch.noMappings')}</p>
            ) : (
              <ul className="flex flex-col gap-2">
                {data.data.mappings.map((m) => (
                  <li
                    key={m.id}
                    className="bg-surface-sunken flex flex-wrap items-center justify-between gap-2 rounded-xl px-3 py-2 text-sm"
                  >
                    <span>
                      {t(`tracking.funnels.sources.${m.event}`)} → {providerLabel(m.provider)}{' '}
                      <bdi dir="ltr" className="text-content-secondary">
                        {m.provider_event}
                      </bdi>
                    </span>
                    {canManage ? (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="max-md:h-11"
                        disabled={remove.isPending}
                        onClick={() => remove.mutate({ projectId: project.id, id: m.id })}
                      >
                        {t('tracking.forms.builder.remove')}
                      </Button>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
            {canManage ? (
              <MappingForm
                projectId={project.id}
                events={data.data.events}
                providers={data.data.providers}
              />
            ) : null}
          </Card>

          <Card className="flex flex-col gap-3 p-4 sm:p-5">
            <h2 className="text-content text-base font-semibold">
              {t('tracking.dispatch.policy.title')}
            </h2>
            {canManage ? (
              <PolicyForm
                key={`${data.data.policy.consent_policy}-${String(data.data.policy.retention_days)}`}
                projectId={project.id}
                consent={data.data.policy.consent_policy}
                retention={data.data.policy.retention_days}
              />
            ) : (
              <p className="text-content-secondary text-sm">
                {t(`tracking.dispatch.policy.options.${data.data.policy.consent_policy}`)} ·{' '}
                {t('tracking.dispatch.policy.days', { count: data.data.policy.retention_days })}
              </p>
            )}
          </Card>

          <Card className="flex flex-col gap-3 p-4 sm:p-5">
            <h2 className="text-content text-base font-semibold">
              {t('tracking.dispatch.log.title')}
            </h2>
            <p className="text-content-tertiary text-xs">{t('tracking.dispatch.log.note')}</p>
            {data.data.dispatches.length === 0 ? (
              <p className="text-content-secondary text-sm">{t('tracking.dispatch.log.empty')}</p>
            ) : (
              <div
                className="overflow-x-auto"
                role="region"
                tabIndex={0}
                aria-label={t('tracking.dispatch.log.title')}
              >
                <table className="w-full min-w-[44rem] text-sm">
                  <thead>
                    <tr className="text-content-tertiary text-xs">
                      {['time', 'event', 'provider', 'status', 'detail', 'evidence', ''].map(
                        (c, i) => (
                          <th
                            key={`${c}${String(i)}`}
                            scope="col"
                            className="px-2 py-2 text-start font-medium"
                          >
                            {c === '' ? (
                              <span className="sr-only">
                                {t('tracking.dispatch.log.cols.actions')}
                              </span>
                            ) : (
                              t(`tracking.dispatch.log.cols.${c}`)
                            )}
                          </th>
                        ),
                      )}
                    </tr>
                  </thead>
                  <tbody>
                    {data.data.dispatches.map((d) => (
                      <tr key={d.id} className="border-line-subtle border-t align-top">
                        <td className="px-2 py-2 tabular-nums" dir="ltr">
                          {when.format(new Date(d.occurred_at))}
                        </td>
                        <td className="px-2 py-2">
                          {t(`tracking.funnels.sources.${d.event}`)}{' '}
                          <span className="text-content-tertiary text-xs">
                            ({t(`tracking.dispatch.channel.${d.channel}`)})
                          </span>
                        </td>
                        <td className="px-2 py-2">
                          {providerLabel(d.provider)}{' '}
                          <bdi dir="ltr" className="text-content-tertiary text-xs">
                            {d.provider_event}
                          </bdi>
                        </td>
                        <td className="px-2 py-2">
                          <Badge tone={STATUS_TONE[d.status]}>
                            {t(`tracking.dispatch.status.${d.status}`)}
                          </Badge>
                        </td>
                        <td className="px-2 py-2 text-xs">
                          {d.skip_reason
                            ? t(`tracking.dispatch.skip.${d.skip_reason}`)
                            : (d.error_category ??
                              (d.source_state ? t('tracking.dispatch.simulated') : '—'))}
                        </td>
                        <td className="px-2 py-2 text-xs">
                          {d.provider_received === null
                            ? t('tracking.dispatch.noProviderAnswer')
                            : d.provider_received
                              ? t('tracking.dispatch.received')
                              : t('tracking.dispatch.notReceived')}
                        </td>
                        <td className="px-2 py-2">
                          {canManage && (d.status === 'failed' || d.status === 'unknown') ? (
                            <Button
                              type="button"
                              variant="secondary"
                              size="sm"
                              className="max-md:h-11"
                              disabled={retry.isPending}
                              onClick={() => retry.mutate(d.id)}
                            >
                              {t('tracking.dispatch.retry')}
                            </Button>
                          ) : null}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>
        </>
      )}
    </div>
  );
}
