import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CircleAlert, Link2, Lock, RefreshCw, Unlink } from 'lucide-react';
import { PERMISSIONS, useSession } from '@/features/auth';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/shared/ui/card';
import { ProviderLogo } from './ProviderLogo';
import { CredentialsDialog } from './CredentialsDialog';
import { DisconnectDialog } from './DisconnectDialog';
import { useDisconnectProviderMutation, useDiscoverAccountsMutation } from './api';
import type { ConnectionStatus, ProviderRow } from './types';

const STATUS_TONE: Record<
  ConnectionStatus,
  'success' | 'accent' | 'warning' | 'danger' | 'neutral'
> = {
  connected: 'success',
  configured: 'accent',
  not_configured: 'neutral',
  expired: 'warning',
  revoked: 'danger',
  error: 'danger',
};

export function ProviderCard({ provider }: { provider: ProviderRow }) {
  const { t } = useTranslation();
  const { can } = useSession();
  const [isDialogOpen, setDialogOpen] = useState(false);
  const [confirmingDisconnect, setConfirmingDisconnect] = useState(false);
  const discover = useDiscoverAccountsMutation();
  const disconnect = useDisconnectProviderMutation();

  // Reading the integrations page and changing it are different rights: a
  // project manager sees the status, but is not offered controls the server
  // would refuse.
  const canManage = can(PERMISSIONS.workspacesUpdate);
  const hasCredentials = provider.missing_credentials.length === 0;
  const canDiscover = canManage && hasCredentials && provider.capabilities.length > 0;
  const discoverError = discover.error instanceof ApiError ? discover.error : null;

  return (
    <Card className="flex flex-col">
      <CardHeader>
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-center gap-3">
            <ProviderLogo provider={provider.provider} />
            <div>
              <CardTitle>{provider.label}</CardTitle>
              <p className="text-content-tertiary text-xs">{provider.category_label}</p>
            </div>
          </div>
          <div className="flex flex-wrap items-center justify-end gap-1.5">
            {/*
              Said on the card, not only on the figures. Every number this product shows already carries
              `SANDBOX`, so no report was ever dishonest — but "Connected" read identically for a connection
              holding this repository's fixtures and one holding a client's keys, and this is the page where
              somebody decides a provider is ready.
            */}
            {provider.is_sandbox ? (
              <Badge tone="warning">{t('integrations.sandboxCredentials')}</Badge>
            ) : null}
            <Badge tone={STATUS_TONE[provider.status]}>{provider.status_label}</Badge>
          </div>
        </div>
      </CardHeader>

      <CardContent className="flex flex-1 flex-col gap-4">
        {provider.capabilities.length === 0 ? (
          <p className="text-content-secondary text-sm">{t('integrations.noCapabilities')}</p>
        ) : null}

        {provider.status_message ? (
          <p className="text-content-secondary flex items-start gap-2 text-xs">
            <CircleAlert className="text-warning mt-px size-3.5 shrink-0" aria-hidden="true" />
            {provider.status_message}
          </p>
        ) : null}

        {/*
          Technical detail, closed: what the connection can do and which credentials it still wants. A person
          running campaigns needs the status above; whoever sets the connection up opens this.
        */}
        <details className="text-xs">
          <summary className="text-content-secondary cursor-pointer font-medium">
            {t('integrations.advanced')}
          </summary>
          <div className="mt-2 flex flex-col gap-3">
            <p className="text-content-tertiary">
              {t(`integrations.authMethod.${provider.auth_method}`)}
            </p>
            {provider.capabilities.length > 0 ? (
              <ul
                className="flex flex-wrap gap-1.5"
                aria-label={t('integrations.capabilitiesFor', { provider: provider.label })}
              >
                {provider.capabilities.map((capability) => (
                  <li key={capability.slug}>
                    <Badge tone={capability.is_write ? 'warning' : 'neutral'}>
                      {capability.is_write ? <Lock className="size-3" aria-hidden="true" /> : null}
                      {capability.label}
                    </Badge>
                  </li>
                ))}
              </ul>
            ) : null}
            {provider.missing_credentials.length > 0 ? (
              <Alert tone="info" className="text-xs">
                <p className="font-medium">{t('integrations.blockedTitle')}</p>
                <p className="mt-1">
                  {t('integrations.blockedBody', { keys: provider.missing_credentials.join(', ') })}
                </p>
              </Alert>
            ) : null}
          </div>
        </details>

        {discoverError ? (
          <Alert tone="warning" className="text-xs">
            {discoverError.message}
          </Alert>
        ) : null}

        <div className="text-content-secondary mt-auto flex flex-wrap items-center justify-between gap-2 text-xs">
          <span className="ds-numeric">
            {t('integrations.accountCount', { count: provider.account_count })}
          </span>

          <div className="flex flex-wrap gap-1.5">
            {canManage ? (
              <Button variant="secondary" size="sm" onClick={() => setDialogOpen(true)}>
                <Link2 />
                {hasCredentials ? t('integrations.update') : t('integrations.configure')}
              </Button>
            ) : null}

            {canDiscover ? (
              <Button
                variant="ghost"
                size="sm"
                disabled={discover.isPending}
                onClick={() => discover.mutate(provider.provider)}
              >
                <RefreshCw />
                {t('integrations.discover')}
              </Button>
            ) : null}

            {canManage && hasCredentials ? (
              <Button
                variant="ghost"
                size="sm"
                className="text-danger"
                disabled={disconnect.isPending}
                onClick={() => {
                  disconnect.reset();
                  setConfirmingDisconnect(true);
                }}
                aria-label={t('integrations.disconnectFrom', { provider: provider.label })}
              >
                <Unlink />
              </Button>
            ) : null}
          </div>
        </div>
      </CardContent>

      <CredentialsDialog open={isDialogOpen} onOpenChange={setDialogOpen} provider={provider} />
      <DisconnectDialog
        name={provider.label}
        open={confirmingDisconnect}
        pending={disconnect.isPending}
        error={disconnect.error}
        onOpenChange={setConfirmingDisconnect}
        onConfirm={() =>
          disconnect.mutate(provider.provider, { onSuccess: () => setConfirmingDisconnect(false) })
        }
      />
    </Card>
  );
}
