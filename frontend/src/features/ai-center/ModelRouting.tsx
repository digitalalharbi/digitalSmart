import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { KeyRound } from 'lucide-react';
import { api } from '@/shared/api/client';
import { cn } from '@/shared/lib/cn';
import { Button } from '@/shared/ui/button';
import { Input } from '@/shared/ui/input';
import { ChartCard } from '@/shared/ui/viz';

type Routing = {
  credentials: {
    configured: boolean;
    last4: string | null;
    strong_model: string | null;
    fast_model: string | null;
  };
  routing: Array<{
    purpose: string;
    tier: 'strong' | 'fast';
    model: string;
    source: string;
    state: 'READY' | 'AWAITING_MODEL_CREDENTIALS';
  }>;
};

const KEY = ['agent-models'] as const;

/**
 * Which model serves which purpose, and the workspace's own key.
 *
 * Administrators only — model names belong here and nowhere else in the product. The key field is write-only: what
 * comes back is whether one is set and its last four characters.
 */
export function ModelRouting() {
  const { t } = useTranslation();
  const client = useQueryClient();
  const [key, setKey] = useState('');
  const query = useQuery({
    queryKey: KEY,
    queryFn: async (): Promise<Routing> =>
      (await api.get<{ data: Routing }>('/agents/models')).data.data,
  });
  const save = useMutation({
    mutationFn: async (body: { api_key?: string; remove?: boolean }) =>
      (await api.put<{ data: Routing }>('/agents/models', body)).data.data,
    onSuccess: (data) => {
      client.setQueryData(KEY, data);
      setKey('');
    },
  });

  const data = query.data;

  return (
    <ChartCard title={t('aiCenter.models.title')}>
      {data === undefined ? null : (
        <>
          <ul className="divide-line-subtle flex flex-col divide-y">
            {data.routing.map((row) => (
              <li
                key={row.purpose}
                className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm"
              >
                <span className="text-content">{t(`aiCenter.models.purpose.${row.purpose}`)}</span>
                <span className="flex items-center gap-2">
                  <span className="text-content-tertiary font-mono text-xs">{row.model}</span>
                  <span
                    className={cn(
                      'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                      row.state === 'READY'
                        ? 'bg-success-surface text-success'
                        : 'bg-warning-surface text-warning',
                    )}
                  >
                    {t(`aiCenter.models.state.${row.state}`)}
                  </span>
                </span>
              </li>
            ))}
          </ul>
          <form
            className="flex flex-col gap-2 sm:flex-row sm:items-center"
            onSubmit={(event) => {
              event.preventDefault();

              if (key.trim().length >= 20) {
                save.mutate({ api_key: key.trim() });
              }
            }}
          >
            <KeyRound aria-hidden className="text-content-tertiary hidden size-4 sm:block" />
            <Input
              type="password"
              autoComplete="off"
              value={key}
              onChange={(event) => setKey(event.target.value)}
              aria-label={t('aiCenter.models.key')}
              placeholder={
                data.credentials.configured
                  ? t('aiCenter.models.keySet', { last4: data.credentials.last4 ?? '' })
                  : t('aiCenter.models.key')
              }
              className="flex-1"
            />
            <Button type="submit" disabled={save.isPending || key.trim().length < 20}>
              {t('aiCenter.models.save')}
            </Button>
            {data.credentials.configured ? (
              <Button
                type="button"
                variant="ghost"
                disabled={save.isPending}
                onClick={() => save.mutate({ remove: true })}
              >
                {t('aiCenter.models.remove')}
              </Button>
            ) : null}
          </form>
          <p className="text-content-tertiary text-xs">{t('aiCenter.models.note')}</p>
        </>
      )}
    </ChartCard>
  );
}
