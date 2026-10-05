import { useTranslation } from 'react-i18next';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/card';
import { HealthPanel } from './HealthPanel';

export function OverviewPage() {
  const { t } = useTranslation();

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-col gap-1">
        <h1 className="text-content text-2xl font-semibold">{t('overview.title')}</h1>
        <p className="text-content-secondary text-sm">{t('overview.subtitle')}</p>
      </header>

      <div className="grid gap-6 lg:grid-cols-2">
        <HealthPanel />

        <div className="flex flex-col gap-6">
          <Card>
            <CardHeader>
              <CardTitle>{t('overview.foundationTitle')}</CardTitle>
              <CardDescription>{t('overview.foundationBody')}</CardDescription>
            </CardHeader>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>{t('overview.roadmapTitle')}</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-content-secondary text-sm leading-relaxed">
                {t('overview.roadmapBody')}
              </p>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
