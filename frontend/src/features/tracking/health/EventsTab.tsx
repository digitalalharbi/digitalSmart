import { Copy } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { EmptyState } from '@/shared/ui/empty-state';
import { Input } from '@/shared/ui/input';
import { Skeleton } from '@/shared/ui/skeleton';
import { useLinksQuery } from '../links/api';
import {
  useDebugEventsQuery,
  useSaveDataLayerMutation,
  useSnippetQuery,
  useStartTestMutation,
} from './api';
import { ProjectScope } from './ProjectScope';

const BROWSER_EVENTS = [
  'landing_view',
  'form_view',
  'form_start',
  'form_step_completed',
  'form_validation_error',
  'form_submit',
  'whatsapp_click',
] as const;

function DataLayer({ projectId, canManage }: { projectId: string; canManage: boolean }) {
  const { t } = useTranslation();
  const snippet = useSnippetQuery(projectId);
  const save = useSaveDataLayerMutation();
  const [edits, setEdits] = useState<Record<string, string>>({});
  const [copied, setCopied] = useState(false);

  if (snippet.isPending) {
    return <Skeleton className="h-40 w-full" />;
  }

  if (snippet.isError) {
    return <Alert tone="danger">{t('tracking.funnels.failed')}</Alert>;
  }

  const names = { ...snippet.data.data_layer, ...edits };

  return (
    <Card className="flex flex-col gap-4 p-4 sm:p-5">
      <div>
        <h2 className="text-content text-base font-semibold">
          {t('tracking.events.dataLayer.title')}
        </h2>
        <p className="text-content-secondary mt-1 text-sm">{t('tracking.events.dataLayer.body')}</p>
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        {BROWSER_EVENTS.map((event) => (
          <label key={event} className="flex flex-col gap-1 text-sm">
            <span className="text-content-secondary text-xs" dir="ltr">
              {event}
            </span>
            <Input
              dir="ltr"
              value={names[event] ?? ''}
              disabled={!canManage}
              onChange={(e) => setEdits((current) => ({ ...current, [event]: e.target.value }))}
              aria-label={t('tracking.events.dataLayer.name', { event })}
            />
          </label>
        ))}
      </div>

      {save.isError ? <Alert tone="danger">{t('tracking.events.dataLayer.failed')}</Alert> : null}
      {canManage ? (
        <div className="flex justify-end">
          <Button
            type="button"
            disabled={save.isPending || Object.keys(edits).length === 0}
            onClick={() => save.mutate({ projectId, names }, { onSuccess: () => setEdits({}) })}
          >
            {t('tracking.events.dataLayer.save')}
          </Button>
        </div>
      ) : null}

      <div className="flex flex-col gap-2">
        <div className="flex items-center justify-between gap-2">
          <h3 className="text-content text-sm font-semibold">
            {t('tracking.events.snippet.title')}
          </h3>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            className="max-md:h-11"
            onClick={() => {
              void navigator.clipboard
                ?.writeText(snippet.data.snippet)
                .then(() => setCopied(true))
                .catch(() => setCopied(false));
            }}
          >
            <Copy aria-hidden="true" className="size-4" />
            {copied ? t('tracking.links.copied') : t('tracking.links.copyShort')}
          </Button>
        </div>
        <p className="text-content-tertiary text-xs">{t('tracking.events.snippet.body')}</p>
        <pre
          dir="ltr"
          tabIndex={0}
          className="bg-surface-sunken text-content max-h-64 overflow-auto rounded-xl p-3 text-xs"
        >
          {snippet.data.snippet}
        </pre>
      </div>
    </Card>
  );
}

/** Watch a test visit happen, and wire the page to Tag Manager. Test traffic is kept, labelled, and counted nowhere. */
export function EventsTab() {
  const { t, i18n } = useTranslation();
  const { can } = useSession();
  const canManage = can(PERMISSIONS.trackingManage);
  const [project, setProject] = useState<{ id: string; label: string } | null>(null);
  const [linkId, setLinkId] = useState('');
  const [test, setTest] = useState<{ touchpoint: string; url: string } | null>(null);
  const links = useLinksQuery(project?.id ?? null);
  const start = useStartTestMutation();
  const events = useDebugEventsQuery(project?.id ?? null, test?.touchpoint ?? null, test !== null);
  const time = new Intl.DateTimeFormat(
    i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : i18n.language,
    { timeStyle: 'medium' },
  );

  return (
    <div className="flex flex-col gap-5">
      <p className="text-content-secondary max-w-prose text-sm">{t('tracking.events.intro')}</p>

      <ProjectScope
        value={project}
        onChange={(value) => {
          setProject(value);
          setLinkId('');
          setTest(null);
        }}
      />

      {project === null ? (
        <EmptyState
          title={t('tracking.funnels.pick.title')}
          description={t('tracking.funnels.pick.body')}
        />
      ) : (
        <>
          {canManage ? (
            <Card className="flex flex-col gap-4 p-4 sm:p-5">
              <h2 className="text-content text-base font-semibold">
                {t('tracking.events.test.title')}
              </h2>
              <p className="text-content-secondary text-sm">{t('tracking.events.test.body')}</p>
              <div className="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                <label className="flex min-w-0 flex-col gap-1.5 text-sm font-medium">
                  {t('tracking.events.test.link')}
                  <select
                    className="border-line bg-surface text-content h-11 w-full min-w-0 truncate rounded-lg border px-3 text-[16px] md:h-10 md:text-sm"
                    value={linkId}
                    onChange={(e) => setLinkId(e.target.value)}
                  >
                    <option value="">{t('tracking.events.test.choose')}</option>
                    {(links.data ?? []).map((l) => (
                      <option key={l.id} value={l.id}>
                        {l.name}
                      </option>
                    ))}
                  </select>
                </label>
                <Button
                  type="button"
                  disabled={linkId === '' || start.isPending}
                  onClick={() => start.mutate(linkId, { onSuccess: setTest })}
                >
                  {t('tracking.events.test.start')}
                </Button>
              </div>
              {start.isError ? (
                <Alert tone="danger">{t('tracking.events.test.failed')}</Alert>
              ) : null}
              {test !== null ? (
                <div className="flex flex-col gap-2">
                  <p className="text-content text-sm">{t('tracking.events.test.open')}</p>
                  <a
                    href={test.url}
                    target="_blank"
                    rel="noreferrer"
                    dir="ltr"
                    className="text-content-brand text-sm break-all underline"
                  >
                    {test.url}
                  </a>
                  <Badge tone="info">{t('tracking.events.test.label')}</Badge>
                </div>
              ) : null}
            </Card>
          ) : null}

          {test !== null ? (
            <Card className="flex flex-col gap-3 p-4 sm:p-5">
              <h2 className="text-content text-base font-semibold">
                {t('tracking.events.console.title')}
              </h2>
              {events.isPending ? (
                <Skeleton className="h-24 w-full" />
              ) : events.isError ? (
                <Alert tone="danger">{t('tracking.funnels.failed')}</Alert>
              ) : events.data.length === 0 ? (
                <p className="text-content-secondary text-sm" role="status">
                  {t('tracking.events.console.waiting')}
                </p>
              ) : (
                <div
                  className="overflow-x-auto"
                  role="region"
                  tabIndex={0}
                  aria-label={t('tracking.events.console.title')}
                >
                  <table className="w-full min-w-[40rem] text-sm">
                    <thead>
                      <tr className="text-content-tertiary text-xs">
                        {['time', 'event', 'source', 'status', 'id', 'destination'].map((c) => (
                          <th key={c} scope="col" className="px-2 py-2 text-start font-medium">
                            {t(`tracking.events.console.cols.${c}`)}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {events.data.map((e) => (
                        <tr key={e.id} className="border-line-subtle border-t">
                          <td className="px-2 py-2 tabular-nums" dir="ltr">
                            {time.format(new Date(e.at))}
                          </td>
                          <td className="px-2 py-2 font-medium" dir="ltr">
                            {e.event}
                          </td>
                          <td className="px-2 py-2">
                            {t(`tracking.events.console.sources.${e.source}`)}
                          </td>
                          <td className="px-2 py-2">
                            <Badge
                              tone={
                                e.status === 'valid'
                                  ? 'success'
                                  : e.status === 'test'
                                    ? 'info'
                                    : 'warning'
                              }
                            >
                              {t(`tracking.events.console.statuses.${e.status}`)}
                            </Badge>
                          </td>
                          <td className="px-2 py-2 text-xs" dir="ltr">
                            {e.event_id}
                          </td>
                          <td className="px-2 py-2">
                            <Badge>{t(`tracking.health.states.${e.destinations.state}`)}</Badge>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Card>
          ) : null}

          <DataLayer projectId={project.id} canManage={canManage} />
        </>
      )}
    </div>
  );
}
