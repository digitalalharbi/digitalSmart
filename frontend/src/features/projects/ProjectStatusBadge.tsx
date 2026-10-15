import { useTranslation } from 'react-i18next';
import { Badge } from '@/shared/ui/badge';
import { SPENDING_STATUSES, type ProjectStatus } from './types';

const TONE: Record<ProjectStatus, 'success' | 'accent' | 'warning' | 'info' | 'neutral'> = {
  active: 'success',
  planning: 'accent',
  onboarding: 'accent',
  paused: 'warning',
  closing: 'warning',
  completed: 'info',
  archived: 'neutral',
};

export function ProjectStatusBadge({ status }: { status: ProjectStatus }) {
  const { t } = useTranslation();
  const spends = SPENDING_STATUSES.includes(status);

  return (
    <Badge
      tone={TONE[status]}
      // Whether a project can spend is the fact that matters operationally.
      title={spends ? t('projects.spendActive') : t('projects.spendPaused')}
    >
      {t(`projects.status.${status}`)}
    </Badge>
  );
}
