import * as Dialog from '@radix-ui/react-dialog';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useProjectsQuery } from '@/features/projects';
import { ApiError } from '@/shared/api/client';
import { Alert } from '@/shared/ui/alert';
import { Button } from '@/shared/ui/button';
import { EntityPicker } from '@/shared/ui/entity-picker';
import { Field } from '@/shared/ui/field';
import { Input } from '@/shared/ui/input';
import { returnFocus } from '@/shared/ui/return-focus';
import { useBindingOptions, useSaveLinkMutation } from './api';
import type { BindingOption, ConversionGoal, LinkDraft } from './types';

const CHANNELS = [
  'paid',
  'organic',
  'email',
  'sms',
  'offline_qr',
  'referral',
  'direct',
  'other',
] as const;

const GOALS: ConversionGoal[] = ['lead', 'qualified', 'appointment', 'sale'];

const SELECT =
  'border-line bg-surface text-content h-11 w-full min-w-0 truncate rounded-lg border px-3 text-[16px] disabled:opacity-60 md:h-10 md:text-sm';

const PLATFORMS = ['snapchat', 'tiktok', 'meta', 'google_ads', 'x', 'linkedin'] as const;

/** The default naming: the platform, the campaign and the creative say what each parameter is without anybody typing it. */
const DEFAULT_PATTERNS = {
  utm_source: '{{platform}}',
  utm_medium: 'paid',
  utm_campaign: '{{campaign}}',
  utm_content: '{{creative}}',
};

export function LinkBuilder({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation();
  const save = useSaveLinkMutation();
  const [term, setTerm] = useState('');
  const [project, setProject] = useState<{ id: string; label: string } | null>(null);
  const [form, setForm] = useState({
    name: '',
    destination_url: '',
    platform: 'snapchat',
    channel: 'paid',
    campaign: '',
    ad_group: '',
    ad: '',
    creative: '',
    conversion_goal: '',
  });
  const [chain, setChain] = useState({ account: '', campaign: '', adGroup: '', ad: '' });
  const projects = useProjectsQuery({ search: term, per_page: 8 });
  const projectId = project?.id ?? null;
  const accounts = useBindingOptions(projectId, 'accounts', null, open);
  const campaigns = useBindingOptions(
    projectId,
    'campaigns',
    chain.account || null,
    chain.account !== '',
  );
  const adGroups = useBindingOptions(
    projectId,
    'ad_groups',
    chain.campaign || null,
    chain.campaign !== '',
  );
  const ads = useBindingOptions(projectId, 'ads', chain.adGroup || null, chain.adGroup !== '');
  const bound = chain.campaign !== '';
  const chosen = (options: BindingOption[] | undefined, id: string): BindingOption | undefined =>
    options?.find((o) => o.id === id);
  const set = (key: keyof typeof form) => (value: string) =>
    setForm((f) => ({ ...f, [key]: value }));

  const submit = () => {
    if (project === null) {
      return;
    }

    const draft: LinkDraft = {
      project_id: project.id,
      name: form.name,
      destination_url: form.destination_url,
      channel: form.channel,
      template_patterns: DEFAULT_PATTERNS,
      ...(bound
        ? {
            external_account_id: chain.account,
            campaign_id: chain.campaign,
            ...(chain.adGroup ? { ad_group_id: chain.adGroup } : {}),
            ...(chain.ad ? { ad_id: chain.ad } : {}),
          }
        : {
            platform: form.platform,
            ...(form.campaign ? { campaign: form.campaign } : {}),
            ...(form.ad_group ? { ad_group: form.ad_group } : {}),
            ...(form.ad ? { ad: form.ad } : {}),
            ...(form.creative ? { creative: form.creative } : {}),
          }),
      ...(form.conversion_goal ? { conversion_goal: form.conversion_goal as ConversionGoal } : {}),
    };

    save.mutate({ draft }, { onSuccess: () => onOpenChange(false) });
  };

  return (
    <Dialog.Root
      open={open}
      onOpenChange={(next) => {
        if (!next) {
          save.reset();
        }
        onOpenChange(next);
      }}
    >
      <Dialog.Portal>
        <Dialog.Overlay className="bg-overlay animate-in fade-in fixed inset-0 z-40 duration-[var(--ds-duration-fast)]" />
        <Dialog.Content
          onCloseAutoFocus={returnFocus}
          className="bg-surface animate-in fade-in zoom-in-95 fixed start-1/2 top-1/2 z-50 max-h-[92dvh] w-[calc(100vw-2rem)] max-w-xl -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-xl p-5 shadow-lg duration-[var(--ds-duration-base)] sm:p-6 rtl:translate-x-1/2"
        >
          <Dialog.Title className="text-content text-lg font-semibold">
            {t('tracking.links.builder.title')}
          </Dialog.Title>
          <Dialog.Description className="text-content-secondary mt-1 text-sm">
            {t('tracking.links.builder.body')}
          </Dialog.Description>

          <form
            className="mt-4 flex flex-col gap-4"
            onSubmit={(event) => {
              event.preventDefault();
              submit();
            }}
          >
            <EntityPicker
              label={t('tracking.links.builder.project')}
              clearLabel={t('actions.clear')}
              value={project}
              onChange={(option) => {
                setProject(option === null ? null : { id: option.id, label: option.label });
                setChain({ account: '', campaign: '', adGroup: '', ad: '' });
              }}
              onSearch={setTerm}
              options={(projects.data?.data ?? []).map((p) => ({
                id: p.id,
                label: p.name,
                context: p.client.name,
              }))}
              isLoading={projects.isFetching}
            />
            <Field label={t('tracking.links.builder.name')} required>
              {(f) => (
                <Input
                  {...f}
                  value={form.name}
                  onChange={(e) => set('name')(e.target.value)}
                  maxLength={160}
                  required
                />
              )}
            </Field>
            <Field
              label={t('tracking.links.builder.destination')}
              hint={t('tracking.links.builder.destinationHint')}
              required
            >
              {(f) => (
                <Input
                  {...f}
                  type="url"
                  inputMode="url"
                  dir="ltr"
                  value={form.destination_url}
                  onChange={(e) => set('destination_url')(e.target.value)}
                  placeholder="https://"
                  required
                />
              )}
            </Field>
            <details className="border-line rounded-xl border p-3" open={bound}>
              <summary className="text-content flex min-h-11 cursor-pointer items-center text-sm font-medium">
                {t('tracking.links.builder.adSource')}
              </summary>
              <div className="mt-3 flex flex-col gap-4">
                {project === null ? (
                  <p className="text-content-tertiary text-xs">
                    {t('tracking.links.builder.pickProjectFirst')}
                  </p>
                ) : (
                  <>
                    <PickerSelect
                      label={t('tracking.links.builder.account')}
                      value={chain.account}
                      placeholder={t('tracking.links.builder.notBound')}
                      options={accounts.data}
                      loading={accounts.isFetching}
                      onChange={(value) =>
                        setChain({ account: value, campaign: '', adGroup: '', ad: '' })
                      }
                    />
                    {chain.account !== '' ? (
                      <PickerSelect
                        label={t('tracking.links.builder.campaign')}
                        value={chain.campaign}
                        placeholder={t('tracking.links.builder.pickOne')}
                        options={campaigns.data}
                        loading={campaigns.isFetching}
                        onChange={(value) =>
                          setChain((c) => ({ ...c, campaign: value, adGroup: '', ad: '' }))
                        }
                      />
                    ) : null}
                    {chain.campaign !== '' ? (
                      <PickerSelect
                        label={t('tracking.links.builder.adGroup')}
                        value={chain.adGroup}
                        placeholder={t('tracking.links.builder.wholeCampaign')}
                        options={adGroups.data}
                        loading={adGroups.isFetching}
                        onChange={(value) => setChain((c) => ({ ...c, adGroup: value, ad: '' }))}
                      />
                    ) : null}
                    {chain.adGroup !== '' ? (
                      <PickerSelect
                        label={t('tracking.links.builder.ad')}
                        value={chain.ad}
                        placeholder={t('tracking.links.builder.wholeAdGroup')}
                        options={ads.data}
                        loading={ads.isFetching}
                        onChange={(value) => setChain((c) => ({ ...c, ad: value }))}
                      />
                    ) : null}
                  </>
                )}

                {bound ? (
                  <p className="text-content-secondary text-xs" role="status">
                    {t('tracking.links.builder.inherited', {
                      campaign: chosen(campaigns.data, chain.campaign)?.name ?? '',
                      platform: chosen(accounts.data, chain.account)?.detail ?? '',
                    })}
                  </p>
                ) : (
                  <>
                    <Field label={t('tracking.links.builder.platform')}>
                      {(f) => (
                        <div className="-mx-1 min-w-0 overflow-x-clip px-1">
                          <select
                            {...f}
                            className={SELECT}
                            value={form.platform}
                            onChange={(e) => set('platform')(e.target.value)}
                          >
                            {PLATFORMS.map((p) => (
                              <option key={p} value={p}>
                                {t(`tracking.links.platforms.${p}`)}
                              </option>
                            ))}
                          </select>
                        </div>
                      )}
                    </Field>
                    <details className="border-line rounded-lg border p-3">
                      <summary className="text-content-secondary flex min-h-11 cursor-pointer items-center text-xs">
                        {t('tracking.links.builder.manual')}
                      </summary>
                      <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label={t('tracking.links.builder.campaign')}>
                          {(f) => (
                            <Input
                              {...f}
                              value={form.campaign}
                              onChange={(e) => set('campaign')(e.target.value)}
                            />
                          )}
                        </Field>
                        <Field label={t('tracking.links.builder.creative')}>
                          {(f) => (
                            <Input
                              {...f}
                              value={form.creative}
                              onChange={(e) => set('creative')(e.target.value)}
                            />
                          )}
                        </Field>
                        <Field label={t('tracking.links.builder.adGroup')}>
                          {(f) => (
                            <Input
                              {...f}
                              value={form.ad_group}
                              onChange={(e) => set('ad_group')(e.target.value)}
                            />
                          )}
                        </Field>
                        <Field label={t('tracking.links.builder.ad')}>
                          {(f) => (
                            <Input
                              {...f}
                              value={form.ad}
                              onChange={(e) => set('ad')(e.target.value)}
                            />
                          )}
                        </Field>
                      </div>
                    </details>
                  </>
                )}
              </div>
            </details>
            <Field
              label={t('tracking.links.builder.channel')}
              hint={t('tracking.links.builder.channelHint')}
            >
              {(f) => (
                <div className="-mx-1 min-w-0 overflow-x-clip px-1">
                  <select
                    {...f}
                    className={SELECT}
                    value={form.channel}
                    onChange={(e) => set('channel')(e.target.value)}
                  >
                    {CHANNELS.map((c) => (
                      <option key={c} value={c}>
                        {t(`tracking.links.channels.${c}`)}
                      </option>
                    ))}
                  </select>
                </div>
              )}
            </Field>
            <Field
              label={t('tracking.links.builder.goal')}
              hint={t('tracking.links.builder.goalHint')}
            >
              {(f) => (
                <div className="-mx-1 min-w-0 overflow-x-clip px-1">
                  <select
                    {...f}
                    className={SELECT}
                    value={form.conversion_goal}
                    onChange={(e) => set('conversion_goal')(e.target.value)}
                  >
                    <option value="">{t('tracking.links.builder.noGoal')}</option>
                    {GOALS.map((g) => (
                      <option key={g} value={g}>
                        {t(`tracking.links.goals.${g}`)}
                      </option>
                    ))}
                  </select>
                </div>
              )}
            </Field>
            <p className="text-content-tertiary text-xs">{t('tracking.links.builder.utmNote')}</p>

            {save.isError ? (
              <Alert tone="danger">
                {save.error instanceof ApiError && save.error.message !== ''
                  ? save.error.message
                  : t('tracking.links.builder.failed')}
              </Alert>
            ) : null}

            <div className="flex justify-end gap-2">
              <Dialog.Close asChild>
                <Button type="button" variant="secondary">
                  {t('actions.cancel')}
                </Button>
              </Dialog.Close>
              <Button type="submit" disabled={save.isPending || project === null}>
                {t('tracking.links.builder.create')}
              </Button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

/** One dependent selector: a level of the campaign hierarchy, offered only once its parent is chosen. */
function PickerSelect({
  label,
  value,
  placeholder,
  options,
  loading,
  onChange,
}: {
  label: string;
  value: string;
  placeholder: string;
  options: BindingOption[] | undefined;
  loading: boolean;
  onChange: (value: string) => void;
}) {
  return (
    <Field label={label}>
      {(f) => (
        <div className="-mx-1 min-w-0 overflow-x-clip px-1">
          <select
            {...f}
            className={SELECT}
            value={value}
            aria-busy={loading}
            onChange={(e) => onChange(e.target.value)}
          >
            <option value="">{placeholder}</option>
            {(options ?? []).map((o) => (
              <option key={o.id} value={o.id}>
                {o.name}
              </option>
            ))}
          </select>
        </div>
      )}
    </Field>
  );
}
