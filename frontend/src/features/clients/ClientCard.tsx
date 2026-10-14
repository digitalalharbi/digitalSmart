import { useTranslation } from 'react-i18next';
import { formatNumber } from '@/shared/lib/format';
import { useUiStore } from '@/shared/store/ui-store';
import { Badge } from '@/shared/ui/badge';
import { Card, CardContent } from '@/shared/ui/card';
import { ClientRowActions } from './ClientRowActions';
import { StatusBadge } from './StatusBadge';
import type { Client } from './types';

/**
 * One client, as a card.
 *
 * The grid exists because a client list is a list of *relationships*, and a table of five columns reads like
 * an inventory. What a card can do that a row cannot is give the name room and put the status, the industry
 * and the number of projects where the eye lands after it.
 *
 * **It carries only what the client list actually knows.** The obvious temptation on a card this size is
 * spend, revenue and ROAS — and the clients endpoint returns none of them, because those figures are scoped
 * to a period, a platform and a revenue authority that this screen has not chosen. A card that showed them
 * would be inventing a number or quietly picking a window on the reader's behalf; both are the kind of thing
 * the rest of this product refuses to do.
 */
export function ClientCard({
  client,
  onEdit,
}: {
  client: Client;
  onEdit: (client: Client) => void;
}) {
  const { t } = useTranslation();
  const locale = useUiStore((state) => state.locale);

  const initials = client.name
    .replace(/^DEMO\s+—\s+/u, '')
    .split(/\s+/u)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('');

  return (
    /*
      A named article, not an anonymous div. A card is a self-contained record, and naming it gives a screen
      reader something to announce on entry and a test something to address that is not a CSS class.
    */
    <Card
      role="article"
      aria-label={client.name}
      className="hover:border-line ease-standard transition-[border-color,box-shadow] duration-[var(--ds-duration-fast)] hover:shadow-sm"
    >
      <CardContent className="flex flex-col gap-4 pt-6">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            {/*
              Initials, not a logo: nothing in this product stores a client's mark yet, and a generated
              avatar that looked like a brand asset would be a claim about something nobody uploaded.
            */}
            <span
              aria-hidden="true"
              className="bg-accent-subtle text-content-brand grid size-11 shrink-0 place-items-center rounded-2xl text-sm font-semibold"
            >
              {initials}
            </span>
            <div className="min-w-0">
              <p className="text-content truncate font-medium">{client.name}</p>
              <p className="text-content-tertiary truncate text-xs">
                {client.industry ?? t('clients.card.noIndustry')}
              </p>
            </div>
          </div>

          <ClientRowActions client={client} onEdit={onEdit} />
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={client.status} />
          {client.is_demo ? <Badge tone="warning">{t('status.demo')}</Badge> : null}
        </div>

        <dl className="border-line-subtle flex items-end justify-between gap-4 border-t pt-4">
          <div>
            <dt className="text-content-tertiary ds-overline">{t('clients.columns.projects')}</dt>
            <dd className="text-content ds-figure text-2xl">
              {formatNumber(client.project_count, locale)}
            </dd>
          </div>
          {client.primary_contact_email === null ? null : (
            <div className="min-w-0 text-end">
              <dt className="text-content-tertiary ds-overline">{t('clients.card.contact')}</dt>
              <dd className="text-content-secondary truncate text-sm">
                {client.primary_contact_email}
              </dd>
            </div>
          )}
        </dl>
      </CardContent>
    </Card>
  );
}
