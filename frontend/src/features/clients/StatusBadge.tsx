import { useTranslation } from 'react-i18next';
import { Badge } from '@/shared/ui/badge';
import type { ClientStatus } from './types';

const TONE: Record<ClientStatus, 'success' | 'accent' | 'warning' | 'neutral'> = {
  active: 'success',
  prospect: 'accent',
  paused: 'warning',
  archived: 'neutral',
};

export function StatusBadge({ status }: { status: ClientStatus }) {
  const { t } = useTranslation();

  return <Badge tone={TONE[status]}>{t(`clients.status.${status}`)}</Badge>;
}
