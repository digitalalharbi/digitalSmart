import { useQuery } from '@tanstack/react-query';
import { CheckCircle2, CircleAlert, CircleDashed, CircleX } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';
import { api } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { buttonVariants } from '@/shared/ui/button.variants';
import { PageHeader } from '@/shared/ui/page-header';
import { Skeleton } from '@/shared/ui/skeleton';
import { cn } from '@/shared/lib/cn';

type State = 'ready' | 'needs_attention' | 'blocked' | 'unknown';

type Readiness = {
  project: { id: string; name: string; status: string };
  overall: { state: State; label: string };
  checks: Array<{ key: string; state: State; label: string; reason: string }>;
  ready_count: number;
  total: number;
};

/** Where each question is answered. A checklist that cannot be acted on is a checklist nobody finishes. */
const FIX: Record<string, string> = {
  budget: '/budget-calculator',
  accounts: '/integrations',
  tracking: '/tracking',
  creative: '/creative-hub',
  data_quality: '/campaigns',
  approvals: '/approvals',
  landing_page: '/tracking',
};

const ICON = {
  ready: CheckCircle2,
  needs_attention: CircleAlert,
  blocked: CircleX,
  unknown: CircleDashed,
} as const;
const TONE = {
  ready: 'text-success',
  needs_attention: 'text-warning',
  blocked: 'text-danger',
  unknown: 'text-content-tertiary',
} as const;

/**
 * Is this project ready to run — six questions, each answered in a sentence, each one a link to where it is
 * fixed. Not a technical setup page: no scopes, ids or cursors, and nothing here needs an engineer.
 *
 * "Not tracked" is a state of its own. The product cannot see a landing page, and saying so is better than
 * a green tick it did not earn.
 */
export function ProjectSetupPage() {
  const { t } = useTranslation();
  const { projectId = '' } = useParams();

  const { data, isPending, isError } = useQuery({
    queryKey: ['projects', 'readiness', projectId] as const,
    queryFn: async (): Promise<Readiness> => {
      const { data: body } = await api.get<{ data: Readiness }>(`/projects/${projectId}/readiness`);

      return body.data;
    },
  });

  return (
    <div className="ds-page">
      <PageHeader
        title={data?.project.name ?? t('setup.title')}
        description={t('setup.subtitle')}
        actions={
          <Link to="/projects" className={buttonVariants({ variant: 'ghost' })}>
            {t('setup.back')}
          </Link>
        }
      />

      {isError ? <Alert tone="danger">{t('setup.loadFailed')}</Alert> : null}

      {isPending || data === undefined ? (
        <Skeleton className="h-64 w-full" />
      ) : (
        <>
          <section aria-label={t('setup.progress')} className="flex flex-col gap-2">
            <p className="text-content text-sm font-medium">
              {t('setup.progressLabel', { ready: data.ready_count, total: data.total })} ·{' '}
              {data.overall.label}
            </p>
            <span aria-hidden className="bg-surface-sunken block h-2 overflow-hidden rounded-full">
              <span
                className="bg-accent block h-full rounded-full"
                style={{ width: `${String((data.ready_count / data.total) * 100)}%` }}
              />
            </span>
          </section>

          <ol className="flex flex-col gap-3">
            {data.checks.map((check, index) => {
              const Icon = ICON[check.state];

              return (
                <li
                  key={check.key}
                  className="bg-surface border-line-subtle flex flex-wrap items-center justify-between gap-3 rounded-2xl border p-4 shadow-xs"
                >
                  <div className="flex items-start gap-3">
                    <Icon aria-hidden className={cn('mt-0.5 size-5 shrink-0', TONE[check.state])} />
                    <div>
                      <p className="text-content text-sm font-medium">
                        {index + 1}. {t(`setup.check.${check.key}`)}
                        <span className="text-content-tertiary ms-2 text-xs font-normal">
                          {check.label}
                        </span>
                      </p>
                      <p className="text-content-secondary text-sm">{check.reason}</p>
                    </div>
                  </div>
                  {check.state === 'ready' || check.state === 'unknown' ? null : (
                    <Link
                      to={FIX[check.key] ?? '/'}
                      className={buttonVariants({ variant: 'secondary', size: 'sm' })}
                    >
                      {t('setup.fix')}
                    </Link>
                  )}
                </li>
              );
            })}
          </ol>
        </>
      )}
    </div>
  );
}
