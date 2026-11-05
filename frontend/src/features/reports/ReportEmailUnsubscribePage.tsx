import { useState } from 'react';
import { useParams } from 'react-router';
import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Card, CardContent } from '@/shared/ui/card';
import { unsubscribeFromReportEmails } from './recipients';

/**
 * Where the "stop these emails" link in a report email lands. No account, no shell, one button.
 *
 * Nothing happens on arrival: a mail scanner or a link preview that opens the address must not unsubscribe anybody, so
 * the stop is a press. The answer is the same whatever the link was — the page never says a link was unknown.
 */
export function ReportEmailUnsubscribePage() {
  const { t } = useTranslation();
  const { token = '' } = useParams();
  const [state, setState] = useState<'idle' | 'working' | 'done' | 'failed'>('idle');

  const stop = () => {
    setState('working');
    unsubscribeFromReportEmails(token)
      .then(() => setState('done'))
      .catch(() => setState('failed'));
  };

  return (
    <main className="bg-canvas flex min-h-dvh items-center justify-center p-4">
      <Card className="w-full max-w-md">
        <CardContent className="flex flex-col gap-4 p-6">
          <h1 className="text-content text-xl font-semibold">{t('reports.unsubscribe.title')}</h1>
          {state === 'done' ? (
            <p className="text-content-secondary text-sm" role="status">
              {t('reports.unsubscribe.done')}
            </p>
          ) : (
            <>
              <p className="text-content-secondary text-sm">{t('reports.unsubscribe.body')}</p>
              {state === 'failed' ? (
                <Alert tone="danger">{t('reports.unsubscribe.failed')}</Alert>
              ) : null}
              <div>
                <Button type="button" onClick={stop} disabled={state === 'working'}>
                  {t('reports.unsubscribe.confirm')}
                </Button>
              </div>
            </>
          )}
        </CardContent>
      </Card>
    </main>
  );
}
