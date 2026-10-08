import { useTranslation } from 'react-i18next';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/shared/ui/card';
import type { CrmSyncResult } from './types';

type CrmReadPanelProps = {
  /** The CRM this workspace has connected, by its own label. Null when none is. */
  crmLabel: string | null;
  /** Null until a client is chosen: a CRM portal is one company's and this is the binding. */
  clientId: string | null;
  clientName: string | null;
  onRead: () => void;
  isReading: boolean;
  result: CrmSyncResult | null;
  error: string | null;
  canRead: boolean;
};

/**
 * Read a CRM's deals as leads.
 *
 * **A read, and the panel says so.** The CRM owns a deal's stage; nothing here writes one back, which is why
 * `ChangeLeadStage` refuses a CRM-sourced lead and says where to move it instead. A panel offering to "sync"
 * would imply two-way traffic that does not exist.
 *
 * **The client is asked for rather than inferred**, and that is a limitation stated rather than hidden: a HubSpot
 * portal is one company's CRM and a workspace has several clients, so mapping deals onto one needs a binding
 * somebody made deliberately. Nothing guesses it from a company name.
 *
 * **`left_alone` is reported beside `recorded`.** A deal at a stage this product cannot read a meaning from is
 * read successfully and left where it was, and a bridge that reported only the deals it understood would look
 * like one that read almost nothing.
 */
export function CrmReadPanel({
  crmLabel,
  clientId,
  clientName,
  onRead,
  isReading,
  result,
  error,
  canRead,
}: CrmReadPanelProps) {
  const { t } = useTranslation();

  /*
   * Absent when there is no CRM connected. An empty panel on every workspace that has never connected one would
   * be an advertisement, and the place to connect a provider is the integrations page.
   */
  if (crmLabel === null || !canRead) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('leads.crm.title', { source: crmLabel })}</CardTitle>
        <CardDescription>{t('leads.crm.subtitle', { source: crmLabel })}</CardDescription>
      </CardHeader>

      <CardContent className="flex flex-col gap-3">
        {error === null ? null : <Alert tone="danger">{error}</Alert>}

        {clientId === null ? (
          /* The binding, asked for. The client filter at the top of the page is where it is chosen. */
          <p className="text-content-secondary text-sm">{t('leads.crm.chooseClient')}</p>
        ) : (
          <>
            <p className="text-content-secondary text-sm">
              {t('leads.crm.forClient', { client: clientName ?? '' })}
            </p>

            <div>
              <Button type="button" size="sm" disabled={isReading} onClick={onRead}>
                {isReading ? t('leads.crm.reading') : t('leads.crm.read')}
              </Button>
            </div>
          </>
        )}

        {result === null ? null : (
          <>
            <Alert tone="info">
              {t('leads.crm.result', {
                read: result.read,
                recorded: result.recorded,
                leftAlone: result.left_alone,
              })}
            </Alert>

            {/*
              What kind of figures those are, from the server's own answer rather than guessed from the
              connection. A sandbox count is never presented as a client's pipeline — and the count is as
              quotable as a chart is.
            */}
            {result.source === 'sandbox' ? (
              <Alert tone="warning">{t('leads.crm.sandbox')}</Alert>
            ) : null}
          </>
        )}
      </CardContent>
    </Card>
  );
}
