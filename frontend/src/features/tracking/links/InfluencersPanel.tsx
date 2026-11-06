import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useClientsQuery } from '@/features/clients';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Badge } from '@/shared/ui/badge';
import { Button } from '@/shared/ui/button';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { useInfluencersQuery, useSaveInfluencerMutation, type Influencer } from './influencers';

const PLATFORMS = ['snapchat', 'tiktok', 'meta', 'x', 'youtube', 'other'] as const;

const SELECT =
  'border-line bg-surface text-content h-11 w-full min-w-0 truncate rounded-lg border px-3 text-[16px] md:h-10 md:text-sm';

/**
 * The people and accounts that promote a client's offer, and what valid traffic the links made for them brought.
 *
 * Counts only: no fee is recorded, so nothing here is divided by one. Read when opened, because most readers never need it.
 */
export function InfluencersPanel({ canManage }: { canManage: boolean }) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);

  return (
    <details
      className="border-line-subtle rounded-2xl border p-4"
      onToggle={(event) => setOpen(event.currentTarget.open)}
    >
      <summary className="text-content cursor-pointer text-sm font-medium">
        {t('tracking.influencers.title')}
      </summary>
      {open ? <Body canManage={canManage} /> : null}
    </details>
  );
}

function Body({ canManage }: { canManage: boolean }) {
  const { t } = useTranslation();
  const influencers = useInfluencersQuery(null);
  const save = useSaveInfluencerMutation();
  const [term, setTerm] = useState('');
  const [client, setClient] = useState<{ id: string; label: string } | null>(null);
  const [name, setName] = useState('');
  const [handle, setHandle] = useState('');
  const [platform, setPlatform] = useState('');
  const clients = useClientsQuery({ search: term, per_page: 8 });
  const error =
    save.error instanceof ApiError && save.error.message !== '' ? save.error.message : null;

  return (
    <div className="mt-3 flex flex-col gap-4">
      <p className="text-content-secondary text-sm">{t('tracking.influencers.hint')}</p>

      {influencers.isError ? <Alert tone="danger">{t('tracking.influencers.failed')}</Alert> : null}

      {influencers.data?.length === 0 ? (
        <p className="text-content-secondary text-sm">{t('tracking.influencers.none')}</p>
      ) : null}

      <ul aria-label={t('tracking.influencers.title')} className="flex flex-col gap-2">
        {(influencers.data ?? []).map((influencer) => (
          <InfluencerRow key={influencer.id} influencer={influencer} canManage={canManage} />
        ))}
      </ul>

      {canManage ? (
        <form
          className="flex flex-col gap-3"
          onSubmit={(event) => {
            event.preventDefault();

            if (client === null) {
              return;
            }

            save.mutate(
              {
                draft: {
                  client_id: client.id,
                  name,
                  ...(handle !== '' ? { handle } : {}),
                  ...(platform !== '' ? { platform } : {}),
                },
              },
              {
                onSuccess: () => {
                  setName('');
                  setHandle('');
                  setPlatform('');
                },
              },
            );
          }}
        >
          <EntityPicker
            label={t('tracking.influencers.client')}
            clearLabel={t('actions.clear')}
            value={client}
            onChange={(option) =>
              setClient(option === null ? null : { id: option.id, label: option.label })
            }
            onSearch={setTerm}
            options={(clients.data?.data ?? []).map((c) => ({ id: c.id, label: c.name }))}
            isLoading={clients.isFetching}
          />
          <Field label={t('tracking.influencers.name')} required>
            {(f) => <Input {...f} value={name} onChange={(e) => setName(e.target.value)} />}
          </Field>
          <Field label={t('tracking.influencers.handle')}>
            {(f) => (
              <Input {...f} dir="ltr" value={handle} onChange={(e) => setHandle(e.target.value)} />
            )}
          </Field>
          <Field label={t('tracking.influencers.platform')}>
            {(f) => (
              <select
                {...f}
                className={SELECT}
                value={platform}
                onChange={(e) => setPlatform(e.target.value)}
              >
                <option value="">{t('tracking.influencers.noPlatform')}</option>
                {PLATFORMS.map((p) => (
                  <option key={p} value={p}>
                    {t(`tracking.influencers.platforms.${p}`)}
                  </option>
                ))}
              </select>
            )}
          </Field>
          {error === null ? null : <Alert tone="danger">{error}</Alert>}
          <div>
            <Button
              type="submit"
              disabled={save.isPending || client === null || name.trim() === ''}
            >
              {t('tracking.influencers.add')}
            </Button>
          </div>
        </form>
      ) : null}
    </div>
  );
}

function InfluencerRow({ influencer, canManage }: { influencer: Influencer; canManage: boolean }) {
  const { t } = useTranslation();
  const save = useSaveInfluencerMutation();

  return (
    <li className="border-line-subtle flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border p-3">
      <div className="min-w-0 flex-1">
        <p className="text-content text-sm font-medium">{influencer.name}</p>
        <p className="text-content-secondary text-xs">
          {influencer.client.name}
          {influencer.handle === null ? null : (
            <>
              {' · '}
              <span dir="ltr">{influencer.handle}</span>
            </>
          )}
        </p>
      </div>
      <p className="text-content-secondary text-xs tabular-nums">
        {t('tracking.influencers.totals', {
          links: influencer.totals.links,
          clicks: influencer.totals.clicks,
        })}
      </p>
      {influencer.status === 'archived' ? (
        <Badge tone="neutral">{t('tracking.influencers.archived')}</Badge>
      ) : null}
      {canManage ? (
        <Button
          type="button"
          variant="secondary"
          size="sm"
          disabled={save.isPending}
          onClick={() =>
            save.mutate({
              id: influencer.id,
              draft: {
                client_id: influencer.client.id,
                name: influencer.name,
                ...(influencer.handle === null ? {} : { handle: influencer.handle }),
                ...(influencer.platform === null ? {} : { platform: influencer.platform }),
                status: influencer.status === 'active' ? 'archived' : 'active',
              },
            })
          }
        >
          {influencer.status === 'active'
            ? t('tracking.influencers.archive')
            : t('tracking.influencers.restore')}
        </Button>
      ) : null}
    </li>
  );
}
