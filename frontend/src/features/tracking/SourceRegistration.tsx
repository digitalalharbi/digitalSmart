import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { Client } from '@/features/clients';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card } from '@/shared/ui/card';
import { Skeleton } from '@/shared/ui/skeleton';
import { useCreateSourceMutation, usePropertiesQuery } from './useTracking';
import type { DiscoveredProperty } from './types';

/**
 * Register an analytics property as measuring a client.
 *
 * Discovery-driven rather than a text field, and that is the point: nobody can be expected to type
 * `properties/412006881` correctly, and a mistyped property id produces a source that syncs
 * nothing while looking perfectly configured.
 *
 * The list is deliberately unfiltered — an agency's Google account holds properties for clients it
 * no longer works with — so the site and the `G-` measurement id are shown beside every one. A list
 * of numeric ids would be unusable for the single decision this exists to support.
 */
export function SourceRegistration({
  clientId,
  clients,
}: {
  clientId: string | null;
  clients: Client[];
}) {
  const { t } = useTranslation();
  const [isOpen, setIsOpen] = useState(false);
  const [chosenClient, setChosenClient] = useState<string | null>(clientId);
  const properties = usePropertiesQuery(isOpen ? 'google_analytics' : null);
  const create = useCreateSourceMutation();

  const targetClient = chosenClient ?? clientId;

  function register(property: DiscoveredProperty) {
    if (targetClient === null) {
      return;
    }

    create.mutate(
      {
        client_id: targetClient,
        project_id: null,
        kind: 'analytics',
        provider: 'google_analytics',
        external_id: property.property_id,
        name: property.display_name,
      },
      { onSuccess: () => setIsOpen(false) },
    );
  }

  if (!isOpen) {
    return (
      <Button variant="secondary" size="sm" onClick={() => setIsOpen(true)}>
        {t('tracking.sources.connect')}
      </Button>
    );
  }

  return (
    <Card className="w-full p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-content text-sm font-medium">{t('tracking.register.title')}</p>
          <p className="text-content-secondary mt-1 text-sm">{t('tracking.register.subtitle')}</p>
        </div>
        <Button variant="ghost" size="sm" onClick={() => setIsOpen(false)}>
          {t('common.cancel')}
        </Button>
      </div>

      {clientId === null ? (
        <label className="mt-4 flex flex-col gap-1.5">
          <span className="text-content text-sm font-medium">{t('tracking.register.client')}</span>
          <select
            className="border-line bg-surface text-content rounded-lg border px-3 py-2 text-sm"
            value={chosenClient ?? ''}
            onChange={(event) =>
              setChosenClient(event.target.value === '' ? null : event.target.value)
            }
          >
            <option value="">{t('tracking.register.chooseClient')}</option>
            {clients.map((client) => (
              <option key={client.id} value={client.id}>
                {client.name}
              </option>
            ))}
          </select>
        </label>
      ) : null}

      {create.isError ? (
        <Alert tone="danger" className="mt-4">
          {t('tracking.register.failed')}
        </Alert>
      ) : null}

      <div className="mt-4">
        {properties.isLoading ? (
          <Skeleton className="h-24 w-full" />
        ) : properties.isError ? (
          /*
           * Almost always "nothing is connected yet" or "the OAuth client is not authorised". The
           * integrations page is where either is fixed, so this says so rather than reporting a
           * failure the reader cannot act on from here.
           */
          <Alert tone="warning">{t('tracking.register.unavailable')}</Alert>
        ) : (properties.data ?? []).length === 0 ? (
          <Alert tone="info">{t('tracking.register.none')}</Alert>
        ) : (
          <ul className="flex flex-col gap-2">
            {(properties.data ?? []).map((property) => (
              <li
                key={property.property_id}
                className="border-line-subtle flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-2"
              >
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-1.5">
                    <p className="text-content truncate text-sm font-medium">
                      {property.display_name}
                    </p>
                    {/*
                     * A roll-up counts the same events its source properties do, so measuring a
                     * client through both would double every figure — and nothing later in the
                     * product could detect that it had happened.
                     */}
                    {property.is_aggregate ? (
                      <Badge tone="warning">{t('tracking.register.rollUp')}</Badge>
                    ) : null}
                  </div>
                  <p className="text-content-tertiary truncate text-xs">
                    {property.streams
                      .map((stream) => stream.default_uri ?? stream.measurement_id)
                      .filter((value): value is string => value !== null)
                      .join(' · ') || property.account_display_name}
                  </p>
                </div>
                <Button
                  size="sm"
                  variant="secondary"
                  disabled={targetClient === null || create.isPending}
                  onClick={() => register(property)}
                >
                  {t('tracking.register.use')}
                </Button>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Card>
  );
}
