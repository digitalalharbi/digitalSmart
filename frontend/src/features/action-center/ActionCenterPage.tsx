import { useTranslation } from 'react-i18next';
import { PageHeader } from '@/shared/ui/page-header';
import { ActionCenter } from './ActionCenter';

/** Today: the one list a person works down. */
export function ActionCenterPage() {
  const { t } = useTranslation();

  return (
    <div className="ds-page">
      <PageHeader title={t('actionCenter.title')} />
      <ActionCenter />
    </div>
  );
}
