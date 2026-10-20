import { useTranslation } from 'react-i18next';
import { formatNumber } from '@/shared/lib/format';
import { useUiStore } from '@/shared/store/ui-store';
import { Card, CardContent } from '@/shared/ui/card';
import { DonutChart } from '@/shared/ui/viz';
import { useMemberCountsQuery } from './api';

/**
 * Who has access, as a picture above the directory: active, suspended and everybody. The directory stays a
 * table beneath it because people are looked up and compared row by row.
 */
export function MembersGlance() {
  const { t } = useTranslation();
  const locale = useUiStore((state) => state.locale);
  const counts = useMemberCountsQuery();

  if (counts.data === undefined) {
    return null;
  }

  const a = counts.data.active;
  const s = counts.data.suspended;

  return (
    <Card aria-label={t('administration.glance.label')}>
      <CardContent className="flex flex-wrap items-center gap-6 p-5">
        <DonutChart
          size={96}
          label={t('administration.glance.label')}
          centre={{ value: formatNumber(a + s, locale) }}
          legend={false}
          segments={[
            {
              key: 'active',
              label: t('administration.status.active'),
              value: a,
              colour: 'var(--ds-success-500)',
            },
            {
              key: 'suspended',
              label: t('administration.status.suspended'),
              value: s,
              colour: 'var(--ds-danger-500)',
            },
          ]}
        />
        <dl className="flex flex-wrap gap-x-8 gap-y-2">
          <div>
            <dt className="text-content-tertiary ds-overline">
              {t('administration.status.active')}
            </dt>
            <dd className="text-content ds-numeric text-2xl font-semibold">
              {formatNumber(a, locale)}
            </dd>
          </div>
          <div>
            <dt className="text-content-tertiary ds-overline">
              {t('administration.status.suspended')}
            </dt>
            <dd className="text-content ds-numeric text-2xl font-semibold">
              {formatNumber(s, locale)}
            </dd>
          </div>
        </dl>
      </CardContent>
    </Card>
  );
}
