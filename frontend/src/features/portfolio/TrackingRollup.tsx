import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { api } from '@/shared/api/client';
import { queryKeys } from '@/shared/api/query-keys';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Skeleton } from '@/shared/ui/skeleton';

type Rollup = {
  totals: { healthy: number; warning: number; critical: number };
  projects: Array<{
    project_id: string | null;
    project_name: string;
    client_name: string;
    status: 'healthy' | 'warning' | 'critical';
    sources: number;
    sources_needing_attention: number;
  }>;
  by_kind: Array<{ kind: string; sources: number; needing_attention: number }>;
  workspace_issues: number;
};

/**
 * "92 healthy, 5 warning, 3 critical" — and which ones.
 *
 * Nobody should inspect a hundred projects one at a time to learn which have lost their tracking. The
 * unhealthy ones are listed, worst first, each a link to the tracking centre; the healthy are a count.
 * Workspace-level findings (nothing measuring at all) are said separately rather than folded into a green
 * total.
 */
export function TrackingRollup() {
  const { t } = useTranslation();
  const { data, isPending } = useQuery({
    queryKey: queryKeys.portfolio.tracking(),
    queryFn: async (): Promise<Rollup> => {
      const { data: body } = await api.get<{ data: Rollup }>('/measurement/agency-health');

      return body.data;
    },
    staleTime: 60_000,
  });

  if (isPending || data === undefined) {
    return <Skeleton className="h-32 w-full" />;
  }

  const affected = data.projects.filter((p) => p.status !== 'healthy');

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('portfolio.trackingRollup')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <p className="text-sm">
          <span className="ds-figure text-success text-xl">{data.totals.healthy}</span>{' '}
          {t('portfolio.tracking.healthy')} ·{' '}
          <span className="ds-figure text-warning text-xl">{data.totals.warning}</span>{' '}
          {t('portfolio.tracking.warning')} ·{' '}
          <span className="ds-figure text-danger text-xl">{data.totals.critical}</span>{' '}
          {t('portfolio.tracking.critical')}
        </p>

        {data.workspace_issues > 0 ? (
          <p className="text-content-secondary text-sm">
            {t('portfolio.tracking.workspaceIssues', { count: data.workspace_issues })}
          </p>
        ) : null}

        {affected.length === 0 ? (
          <p className="text-content-tertiary text-sm">{t('portfolio.tracking.allClear')}</p>
        ) : (
          <ul className="flex flex-col">
            {affected.map((project) => (
              <li
                key={project.project_id ?? project.project_name}
                className="border-line-subtle flex items-center justify-between gap-3 border-b py-2 last:border-0"
              >
                <Link to="/tracking" className="text-content truncate text-sm hover:underline">
                  {project.project_name}
                  <span className="text-content-tertiary"> · {project.client_name}</span>
                </Link>
                <Badge tone={project.status === 'critical' ? 'danger' : 'warning'}>
                  {t(`portfolio.tracking.${project.status}`)}
                </Badge>
              </li>
            ))}
          </ul>
        )}

        {data.by_kind.length === 0 ? null : (
          <ul className="flex flex-wrap gap-2">
            {data.by_kind.map((kind) => (
              <li key={kind.kind} className="bg-surface-sunken rounded-full px-3 py-1 text-xs">
                {kind.kind.replace('_', ' ')} · {kind.needing_attention}/{kind.sources}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  );
}
