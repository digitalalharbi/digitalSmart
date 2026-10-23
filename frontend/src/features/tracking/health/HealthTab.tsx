import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Card } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Skeleton } from '@/shared/ui/skeleton';
import { useLinksQuery } from '../links/api';
import { useHealthQuery } from './api';
import { ProjectScope } from './ProjectScope';
import type { HealthCheck, HealthState } from './types';

const TONE: Record<HealthState, 'success' | 'neutral' | 'warning' | 'danger' | 'info' | 'accent'> =
  {
    LIVE: 'success',
    SYNCED: 'success',
    ATTRIBUTED: 'success',
    STALE: 'warning',
    PARTIAL: 'warning',
    SANDBOX: 'info',
    MANUAL: 'neutral',
    ESTIMATED: 'neutral',
    NOT_CONFIGURED: 'warning',
    AWAITING_CREDENTIALS: 'neutral',
    UNSUPPORTED: 'neutral',
  };

const GROUPS: HealthCheck['group'][] = ['source', 'destination', 'browser', 'server', 'quality'];

/** The state is a word first and a colour second: nothing here is carried by colour alone. */
function CheckRow({ check }: { check: HealthCheck }) {
  const { t } = useTranslation();
  const evidence = Object.entries(check.evidence);

  return (
    <li className="bg-surface-sunken flex flex-col gap-1.5 rounded-2xl p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-content text-sm font-medium">
          {t(`tracking.health.checks.${check.key}`, { defaultValue: check.key })}
        </p>
        <Badge tone={TONE[check.state]}>{t(`tracking.health.states.${check.state}`)}</Badge>
      </div>
      <p className="text-content-secondary text-xs">{check.detail}</p>
      {evidence.length > 0 ? (
        <p className="text-content-tertiary text-xs" dir="ltr">
          {evidence.map(([k, v]) => `${k}: ${v === null ? '—' : String(v)}`).join(' · ')}
        </p>
      ) : null}
    </li>
  );
}

/** Is the tracking working? Every verdict shows what it rests on, and a verdict nothing supports is "not configured". */
export function HealthTab() {
  const { t } = useTranslation();
  const [project, setProject] = useState<{ id: string; label: string } | null>(null);
  const [linkId, setLinkId] = useState<string | null>(null);
  const links = useLinksQuery(project?.id ?? null);
  const health = useHealthQuery(project?.id ?? null, linkId);

  return (
    <div className="flex flex-col gap-5">
      <p className="text-content-secondary max-w-prose text-sm">{t('tracking.health.intro')}</p>

      <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,16rem)]">
        <ProjectScope
          value={project}
          onChange={(value) => {
            setProject(value);
            setLinkId(null);
          }}
        />
        <label className="-mx-1 flex min-w-0 flex-col gap-1.5 overflow-x-clip px-1 text-sm font-medium">
          {t('tracking.health.scope')}
          <select
            className="border-line bg-surface text-content h-11 w-full min-w-0 truncate rounded-lg border px-3 text-[16px] md:h-10 md:text-sm"
            value={linkId ?? ''}
            disabled={project === null}
            onChange={(e) => setLinkId(e.target.value === '' ? null : e.target.value)}
          >
            <option value="">{t('tracking.health.wholeProject')}</option>
            {(links.data ?? []).map((l) => (
              <option key={l.id} value={l.id}>
                {l.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      {project === null ? (
        <EmptyState
          title={t('tracking.funnels.pick.title')}
          description={t('tracking.funnels.pick.body')}
        />
      ) : health.isPending ? (
        <Skeleton className="h-48 w-full" />
      ) : health.isError ? (
        <Alert tone="danger">{t('tracking.funnels.failed')}</Alert>
      ) : (
        <>
          <p className="text-content text-sm font-medium" role="status">
            {t('tracking.health.attention', { count: health.data.summary.needs_attention })}
          </p>
          <div className="grid gap-4 lg:grid-cols-2">
            {GROUPS.map((group) => {
              const rows = health.data.checks.filter((c) => c.group === group);

              return rows.length === 0 ? null : (
                <Card key={group} className="flex flex-col gap-3 p-4 sm:p-5">
                  <h2 className="text-content text-base font-semibold">
                    {t(`tracking.health.groups.${group}`)}
                  </h2>
                  <ul className="flex flex-col gap-2">
                    {rows.map((check) => (
                      <CheckRow key={check.key} check={check} />
                    ))}
                  </ul>
                </Card>
              );
            })}
          </div>
        </>
      )}
    </div>
  );
}
