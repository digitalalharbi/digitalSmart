import { useTranslation } from 'react-i18next';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';

type PlaceholderPageProps = {
  /** i18n key under `nav.` for this surface. */
  titleKey: string;
  /** Roadmap phase that delivers it, e.g. "Phase 4". */
  phase: string;
};

/**
 * Honest placeholder: it states which phase delivers the surface instead of
 * showing a fake dashboard. No invented metrics, ever.
 */
export function PlaceholderPage({ titleKey, phase }: PlaceholderPageProps) {
  const { t } = useTranslation();
  const title = t(titleKey);

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-center gap-3">
        <h1 className="text-content text-2xl font-semibold tracking-[-0.02em]">{title}</h1>
        <Badge tone="accent">{t('placeholder.phase', { phase })}</Badge>
      </header>

      <Card className="max-w-2xl">
        <CardHeader>
          <CardTitle>{t('placeholder.title', { section: title })}</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-content-secondary text-sm leading-relaxed">{t('placeholder.body')}</p>
        </CardContent>
      </Card>
    </div>
  );
}
