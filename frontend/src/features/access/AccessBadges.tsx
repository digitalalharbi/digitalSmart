import { useTranslation } from 'react-i18next';
import { Badge } from '@/shared/ui/badge';
import type { AccessSla, AccessStatus } from './types';

const STATUS_TONE: Record<AccessStatus, 'warning' | 'info' | 'success' | 'neutral' | 'accent'> = {
  new: 'warning',
  contacted: 'info',
  waiting_for_reply: 'neutral',
  qualified: 'accent',
  approved: 'success',
  rejected: 'neutral',
  closed: 'neutral',
};

export function StatusBadge({ status }: { status: AccessStatus }) {
  const { t } = useTranslation();

  return <Badge tone={STATUS_TONE[status]}>{t(`access.status.${status}`)}</Badge>;
}

const SLA_TONE = {
  within: 'success',
  approaching: 'warning',
  overdue: 'danger',
  attempted: 'info',
  contacted: 'success',
  closed: 'neutral',
} as const;

/** Where a request stands against the response time. An attempt is never shown as reaching the person. */
export function SlaBadge({ sla }: { sla: AccessSla | null }) {
  const { t } = useTranslation();

  if (sla === null) {
    return null;
  }

  return <Badge tone={SLA_TONE[sla.state]}>{t(`access.sla.${sla.state}`)}</Badge>;
}
