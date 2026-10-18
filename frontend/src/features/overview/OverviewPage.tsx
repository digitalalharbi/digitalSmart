import { useTranslation } from 'react-i18next';
import { PERMISSIONS, useSession } from '@/features/auth';
import { AttentionPanel } from '@/features/attention';
import { HomeSections, useHomeProfiles } from '@/features/home';
import { PageHeader } from '@/shared/ui/page-header';
import { CommandCenter } from './CommandCenter';

/**
 * The landing screen: how the agency is doing.
 *
 * Anyone who may read performance gets the command centre — figures, one large line, a few pictures, and what needs
 * a person. Someone who may not (a creative, say) gets the home composed for what they *do* instead, because a
 * dashboard of figures they cannot see would be an empty page with a title.
 */
export function OverviewPage() {
  const { t } = useTranslation();
  const { can } = useSession();
  const { data: profiles } = useHomeProfiles();

  return (
    <div className="ds-page">
      <PageHeader title={t('overview.title')} />

      {can(PERMISSIONS.analyticsView) ? (
        <CommandCenter />
      ) : profiles === undefined || profiles.profiles.length === 0 ? (
        <AttentionPanel />
      ) : (
        <HomeSections profile={profiles.primary ?? profiles.profiles[0]?.key ?? 'executive'} />
      )}
    </div>
  );
}
