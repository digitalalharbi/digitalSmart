import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';
import { PageHeader } from '@/shared/ui/page-header';
import { TabList } from '@/shared/ui/tab-list';
import { LinksTab } from './links/LinksTab';
import { TrackingCenterPage } from './TrackingCenterPage';

type Tab = 'overview' | 'links';

/**
 * Tracking & Funnels.
 *
 * The area's tabs arrive as the things behind them do: today the health overview that already existed, and the links
 * an advertisement points at. A tab with nothing behind it would be a promise, and this product does not make those.
 */
export function TrackingAreaPage() {
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();
  const active: Tab = params.get('tab') === 'links' ? 'links' : 'overview';

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
        ]}
      />
      <div
        role="tabpanel"
        id={`tracking-area-panel-${active}`}
        aria-labelledby={`tracking-area-tab-${active}`}
      >
        {active === 'links' ? (
          <div className="ds-page">
            <PageHeader
              title={t('tracking.area.title')}
              description={t('tracking.area.description')}
            />
            <LinksTab />
          </div>
        ) : (
          <TrackingCenterPage />
        )}
      </div>
    </div>
  );
}
