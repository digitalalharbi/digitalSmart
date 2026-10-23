import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { PageHeader } from '@/shared/ui/page-header';
import { TabList } from '@/shared/ui/tab-list';
import { ConversionsTab } from './conversions/ConversionsTab';
import { FollowUpTab } from './followup/FollowUpTab';
import { FormsTab } from './forms/FormsTab';
import { FunnelsTab } from './funnels/FunnelsTab';
import { EventsTab } from './health/EventsTab';
import { HealthTab } from './health/HealthTab';
import { LinksTab } from './links/LinksTab';
import { TrackingCenterPage } from './TrackingCenterPage';

type Tab =
  'overview' | 'links' | 'forms' | 'funnels' | 'followup' | 'events' | 'conversions' | 'health';

/**
 * Tracking & Funnels.
 *
 * The area's tabs arrive as the things behind them do: today the health overview that already existed, and the links
 * an advertisement points at. A tab with nothing behind it would be a promise, and this product does not make those.
 */
const TABS: Tab[] = [
  'overview',
  'links',
  'forms',
  'funnels',
  'followup',
  'events',
  'conversions',
  'health',
];

export function TrackingAreaPage() {
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();
  const requested = params.get('tab');
  const active: Tab = TABS.find((tab) => tab === requested) ?? 'overview';

  return (
    <div className="flex flex-col gap-4">
      <TabList<Tab>
        label={t('tracking.area.tabs')}
        idPrefix="tracking-area"
        active={active}
        onChange={(tab) => setParams(tab === 'overview' ? {} : { tab }, { replace: true })}
        tabs={[
          { id: 'overview', label: t('tracking.area.overview') },
          { id: 'links', label: t('tracking.area.links') },
          { id: 'forms', label: t('tracking.area.forms') },
          { id: 'funnels', label: t('tracking.area.funnels') },
          { id: 'followup', label: t('tracking.area.followup') },
          { id: 'events', label: t('tracking.area.events') },
          { id: 'conversions', label: t('tracking.area.conversions') },
          { id: 'health', label: t('tracking.area.health') },
        ]}
      />
      <div role="tabpanel" id="tracking-area-panel" aria-labelledby={`tracking-area-tab-${active}`}>
        {active !== 'overview' ? (
          <div className="ds-page">
            <PageHeader
              title={t('tracking.area.title')}
              description={t('tracking.area.description')}
            />
            {active === 'links' ? (
              <LinksTab />
            ) : active === 'forms' ? (
              <FormsTab />
            ) : active === 'funnels' ? (
              <FunnelsTab />
            ) : active === 'followup' ? (
              <FollowUpTab />
            ) : active === 'events' ? (
              <EventsTab />
            ) : active === 'conversions' ? (
              <ConversionsTab />
            ) : (
              <HealthTab />
            )}
          </div>
        ) : (
          <TrackingCenterPage />
        )}
      </div>
    </div>
  );
}
