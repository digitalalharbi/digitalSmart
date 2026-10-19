import { Link } from 'react-router';
import { useTranslation } from 'react-i18next';
import { AlertTriangle, Users } from 'lucide-react';
import type { PortfolioProject } from '@/features/portfolio';
import { formatNumber } from '@/shared/lib/format';
import { useUiStore } from '@/shared/store/ui-store';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent } from '@/shared/ui/card';
import { HealthRing, ProgressBar } from '@/shared/ui/viz';
import { ProjectRowActions } from './ProjectRowActions';
import { ProjectStatusBadge } from './ProjectStatusBadge';
import { scheduleProgress } from './schedule';
import type { Project } from './types';

function compact(minor: number, currency: string, locale: string): string {
  return new Intl.NumberFormat(locale, {
    style: 'currency',
    currency,
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(minor / 100);
}

/**
 * One project as a picture: its campaign health as a ring, what it spent and produced, how far through its dates it
 * is, and what needs a person. Words are the name, the client and one status — everything else is a figure or a bar.
 */
export function ProjectCard({
  project,
  figures,
  now,
  onEdit,
  onPeople,
}: {
  project: Project;
  figures?: PortfolioProject | undefined;
  /** The moment the page was read, so every card measures the same instant. */
  now: number;
  onEdit: (project: Project) => void;
  onPeople: (project: Project) => void;
}) {
  const { t } = useTranslation();
  const locale = useUiStore((state) => state.locale);
  const spend = figures?.spend.slice().sort((a, b) => b.minor - a.minor)[0];
  const progress = scheduleProgress(project, now);
  const late = progress !== null && progress >= 100 && project.status === 'active';

  return (
    <Card
      role="article"
      aria-label={project.name}
      className="ease-standard relative transition-shadow duration-[var(--ds-duration-fast)] hover:shadow-md"
    >
      <CardContent className="flex flex-col gap-4 pt-6">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <Link
              to={`/projects/${project.id}`}
              className="text-content block truncate font-medium after:absolute after:inset-0 after:content-[''] hover:underline focus-visible:outline-none"
              dir="auto"
            >
              {project.name}
            </Link>
            <p className="text-content-tertiary truncate text-xs" dir="auto">
              {project.client.name}
            </p>
          </div>
          <div className="flex shrink-0 items-center gap-1">
            {figures === undefined ? null : (
              <HealthRing
                counts={figures.campaign_health}
                size={44}
                thickness={6}
                label={t('overview.cc.health.title')}
              />
            )}
            <div className="relative z-10">
              <ProjectRowActions project={project} onEdit={onEdit} />
            </div>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <ProjectStatusBadge status={project.status} />
          {project.is_demo ? <Badge tone="warning">{t('status.demo')}</Badge> : null}
          {project.is_assigned ? <Badge tone="accent">{t('projects.assigned')}</Badge> : null}
          {figures !== undefined && figures.needs_attention > 0 ? (
            <Badge tone="warning">
              <AlertTriangle aria-hidden className="size-3" />
              {formatNumber(figures.needs_attention, locale)}
            </Badge>
          ) : null}
        </div>

        {progress === null ? null : (
          <div className="flex flex-col gap-1.5">
            <ProgressBar
              value={progress}
              tone={late ? 'bad' : 'brand'}
              label={t('projects.card.schedule')}
            />
            <div
              className="text-content-tertiary ds-numeric flex justify-between text-xs"
              dir="ltr"
            >
              <span>{project.starts_on}</span>
              <span>{project.ends_on}</span>
            </div>
          </div>
        )}

        <dl className="border-line-subtle flex flex-wrap items-end gap-x-5 gap-y-3 border-t pt-4">
          {figures === undefined ? null : (
            <>
              <div>
                <dt className="text-content-tertiary ds-overline">{t('clients.card.spend')}</dt>
                <dd className="text-content ds-numeric text-xl font-semibold">
                  {spend === undefined ? '—' : compact(spend.minor, spend.currency, locale)}
                </dd>
              </div>
              <div>
                <dt className="text-content-tertiary ds-overline">
                  {t('projects.card.campaigns')}
                </dt>
                <dd className="text-content ds-numeric text-xl font-semibold">
                  {formatNumber(figures.active_campaigns, locale)}
                </dd>
              </div>
              <div>
                <dt className="text-content-tertiary ds-overline">{t('projects.card.work')}</dt>
                <dd className="text-content ds-numeric text-xl font-semibold">
                  {formatNumber(figures.open_work, locale)}
                </dd>
              </div>
            </>
          )}
          <div className="relative z-10 ms-auto">
            <button
              type="button"
              onClick={() => onPeople(project)}
              aria-label={t('projects.people.open')}
              className="bg-surface-sunken text-content-secondary hover:text-content inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold focus-visible:ring-2 focus-visible:ring-[var(--ds-focus-ring)] focus-visible:outline-none"
            >
              <Users aria-hidden className="size-3.5" />
              <span className="ds-numeric">{formatNumber(project.member_count, locale)}</span>
            </button>
          </div>
        </dl>
      </CardContent>
    </Card>
  );
}
