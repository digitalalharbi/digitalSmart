import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { Skeleton } from '@/shared/ui/skeleton';
import { useLeadMilestoneMutation, useLeadTimelineQuery } from './api';

/**
 * Where a lead came from and what happened to it, as far as this product recorded.
 *
 * What it could not record is said, not drawn: a lead with no tracked visit shows the pipeline events and a sentence that
 * earlier touchpoints may have happened without being recorded — never an implication that there were none.
 */
export function LeadTrackedPath({ leadId, canManage }: { leadId: string; canManage: boolean }) {
  const { t, i18n } = useTranslation();
  const timeline = useLeadTimelineQuery(leadId);
  const milestone = useLeadMilestoneMutation();
  const when = new Intl.DateTimeFormat(
    i18n.language.startsWith('ar') ? 'ar-SA-u-nu-latn' : i18n.language,
    {
      dateStyle: 'medium',
      timeStyle: 'short',
    },
  );

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('tracking.path.title')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {timeline.isPending ? (
          <Skeleton className="h-24 w-full" />
        ) : timeline.isError ? (
          <Alert tone="danger">{t('tracking.path.failed')}</Alert>
        ) : (
          <>
            {timeline.data.note ? <Alert tone="warning">{timeline.data.note}</Alert> : null}
            <ol className="flex flex-col gap-2">
              {timeline.data.events.map((event, index) => (
                <li
                  key={`${event.at}-${event.name}-${String(index)}`}
                  className="bg-surface-sunken flex flex-wrap items-center justify-between gap-2 rounded-xl px-3 py-2"
                >
                  <div className="min-w-0">
                    <p className="text-content text-sm font-medium">
                      {t(`tracking.path.events.${event.name}`, { defaultValue: event.name })}
                    </p>
                    <p className="text-content-tertiary text-xs">
                      {when.format(new Date(event.at))}
                    </p>
                  </div>
                  <Badge>{t(`tracking.path.sources.${event.source}`)}</Badge>
                </li>
              ))}
            </ol>
            {canManage ? (
              <div className="flex flex-wrap gap-2">
                {(['appointment_booked', 'opportunity_created'] as const).map((type) => (
                  <Button
                    key={type}
                    type="button"
                    variant="secondary"
                    size="sm"
                    className="max-md:h-11"
                    disabled={milestone.isPending}
                    onClick={() => milestone.mutate({ leadId, type })}
                  >
                    {t(`tracking.path.record.${type}`)}
                  </Button>
                ))}
              </div>
            ) : null}
          </>
        )}
      </CardContent>
    </Card>
  );
}
