import { CircleCheck, CircleSlash, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent } from '@/shared/ui/card';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { EmptyState } from '@/shared/ui/empty-state';
import { LoadingRegion } from '@/shared/ui/loading-region';
import { Skeleton } from '@/shared/ui/skeleton';
import { IssueList } from './IssueList';
import { useReadinessQuery } from './useTracking';
import type { MeasurementSource } from './types';

type ContainerReadinessProps = {
  /** Every source on the page; the tag containers are picked out here rather than by the caller. */
  sources: MeasurementSource[];
  canManage: boolean;
};

/**
 * Whether a client's tag container is actually doing anything.
 *
 * Separate from the health panel on purpose, and the separation is the whole design. Health is derived from this
 * product's own tables and always answers; this reaches Tag Manager on the workspace's credentials, which can be
 * slow, rate-limited or unauthorised. Folding one into the other would let a provider outage empty the page of
 * facts it already had — so this loads on its own, fails on its own, and says so.
 *
 * Asked per container rather than for all of them at once: each answer is a live provider call, and a page that
 * fired one per container on load would be slow in proportion to how much a client has set up.
 *
 * Three things it publishes that nothing else in the product can:
 *
 * **Published state, not saved state.** A change saved in Tag Manager and never published is not live, and this
 * is the only screen that can tell the difference. "Edited since publishing" is the single most common reason a
 * tag that looks correct is not firing.
 *
 * **The measurement id cross-check.** The expected ids come from the client's *own* analytics sources, so this
 * compares what somebody registered as measuring this client against what the published container actually
 * refers to. Those two drifting apart is invisible everywhere else.
 *
 * **Configuration, not events.** A container has no freshness and this panel shows none: an absence of events is
 * not a container's fault, and labelling every container stale would teach a reader to ignore the word.
 */
export function ContainerReadiness({ sources, canManage }: ContainerReadinessProps) {
  const { t } = useTranslation();

  const containers = sources.filter((source) => source.kind === 'tag_manager');
  const [selected, setSelected] = useState<string | null>(null);

  const readiness = useReadinessQuery(canManage ? selected : null);

  if (containers.length === 0) {
    return (
      <EmptyState
        title={t('tracking.containers.emptyTitle')}
        description={t('tracking.containers.emptyBody')}
      />
    );
  }

  if (!canManage) {
    /*
     * Published rather than hidden. Reading a container's configuration reaches the provider on the workspace's
     * credentials, which is a setup permission rather than a reporting one — and a reader who cannot see why the
     * panel is empty assumes the containers are not connected.
     */
    return <Alert tone="info">{t('tracking.containers.needsManage')}</Alert>;
  }

  const failure = readiness.error instanceof ApiError ? readiness.error.message : null;

  return (
    <div className="flex flex-col gap-3">
      <ChoiceGroup label={t('tracking.containers.pick')}>
        <div className="flex flex-wrap gap-2">
          {containers.map((container) => (
            <Choice
              key={container.id}
              isSelected={selected === container.id}
              onSelect={() => setSelected(container.id)}
            >
              {container.name}
            </Choice>
          ))}
        </div>
      </ChoiceGroup>

      {selected === null ? (
        <p className="text-content-tertiary text-sm">{t('tracking.containers.pickHint')}</p>
      ) : null}

      {selected !== null && readiness.isPending ? (
        <LoadingRegion label={t('tracking.containers.loading')} className="flex flex-col gap-2">
          <Skeleton className="h-24" />
          <Skeleton className="h-16" />
        </LoadingRegion>
      ) : null}

      {/*
        The provider's own words. A container read that failed is not a container that is misconfigured, and
        showing "no issues" after a failed call would be the worst of the two possible wrong answers.
      */}
      {failure === null ? null : <Alert tone="warning">{failure}</Alert>}

      {readiness.data === undefined ? null : (
        <>
          <Card>
            <CardContent className="flex flex-col gap-4 p-5">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-content font-medium">{readiness.data.container.name}</p>
                  {/* The only id that proves a container is the right one. */}
                  <p className="text-content-tertiary font-mono text-xs">
                    {readiness.data.container.public_id}
                  </p>
                </div>

                <Badge tone={readiness.data.is_ready ? 'success' : 'warning'}>
                  {readiness.data.is_ready
                    ? t('tracking.containers.ready')
                    : t('tracking.containers.notReady')}
                </Badge>
              </div>

              <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Fact
                  label={t('tracking.containers.published')}
                  value={
                    readiness.data.has_live_version
                      ? t('tracking.containers.hasLiveVersion')
                      : t('tracking.containers.neverPublished')
                  }
                  tone={readiness.data.has_live_version ? 'ok' : 'bad'}
                />
                <Fact
                  label={t('tracking.containers.changes')}
                  value={
                    readiness.data.has_unpublished_changes
                      ? t('tracking.containers.editedSincePublishing')
                      : t('tracking.containers.nothingUnpublished')
                  }
                  /*
                    Not an error, and not nothing. Unpublished changes mean the live container is **not** what
                    somebody is looking at in Tag Manager, which is the commonest reason a correct-looking tag
                    is not firing.
                  */
                  tone={readiness.data.has_unpublished_changes ? 'warn' : 'ok'}
                />
                <Fact
                  label={t('tracking.containers.publishedTags')}
                  value={String(readiness.data.published_tag_count)}
                  tone={readiness.data.published_tag_count > 0 ? 'ok' : 'bad'}
                />
                <Fact
                  label={t('tracking.containers.criticalIssues')}
                  value={String(readiness.data.critical_count)}
                  tone={readiness.data.critical_count === 0 ? 'ok' : 'bad'}
                />
              </dl>

              {/*
                The cross-check, and the reason this panel is worth building. The expected ids come from the
                client's own analytics sources rather than from anything typed here, so a container publishing a
                different property than the one somebody registered shows up — and shows up nowhere else.
              */}
              <div className="border-line-subtle flex flex-col gap-2 border-t pt-4">
                <p className="text-content text-sm font-medium">
                  {t('tracking.containers.measurementIds')}
                </p>
                <p className="text-content-tertiary text-xs">
                  {t('tracking.containers.measurementIdsHint')}
                </p>

                <div className="flex flex-wrap gap-4">
                  <IdList
                    label={t('tracking.containers.expected')}
                    ids={readiness.data.measurement_ids_expected}
                    empty={t('tracking.containers.noneExpected')}
                  />
                  <IdList
                    label={t('tracking.containers.found')}
                    ids={readiness.data.measurement_ids_found}
                    empty={t('tracking.containers.noneFound')}
                  />
                </div>
              </div>
            </CardContent>
          </Card>

          <IssueList issues={readiness.data.issues} />
        </>
      )}
    </div>
  );
}

function Fact({
  label,
  value,
  tone,
}: {
  label: string;
  value: string;
  tone: 'ok' | 'warn' | 'bad';
}) {
  const icon =
    tone === 'ok' ? (
      <CircleCheck aria-hidden className="text-success size-4" />
    ) : tone === 'warn' ? (
      <TriangleAlert aria-hidden className="text-warning size-4" />
    ) : (
      <CircleSlash aria-hidden className="text-danger size-4" />
    );

  return (
    <div className="flex flex-col gap-1">
      <dt className="text-content-tertiary text-xs">{label}</dt>
      {/* The icon is never the only channel: the word beside it says the same thing. */}
      <dd className="text-content flex items-center gap-1.5 text-sm">
        {icon}
        {value}
      </dd>
    </div>
  );
}

function IdList({ label, ids, empty }: { label: string; ids: string[]; empty: string }) {
  return (
    <div className="flex flex-col gap-1">
      <p className="text-content-tertiary text-xs">{label}</p>
      {ids.length === 0 ? (
        <p className="text-content-secondary text-sm">{empty}</p>
      ) : (
        <ul className="flex flex-wrap gap-1.5">
          {ids.map((id) => (
            <li key={id}>
              <Badge tone="neutral" className="font-mono">
                {id}
              </Badge>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
