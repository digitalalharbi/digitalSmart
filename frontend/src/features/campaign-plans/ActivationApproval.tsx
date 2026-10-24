import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { formatMinor, localeTag } from '@/shared/lib/format';
import { providerName } from '@/shared/lib/providers';
import { Button } from '@/shared/ui/button';
import type { Plan } from './api';

/**
 * Approving a launch to start delivering. Everything that will start spending is on the screen at once — platform, account,
 * budget, schedule, conversion, tracking and audience — and the button stays disabled until the approver says they have read it.
 * There is no one-click path from a plan to money being spent.
 */
export function ActivationApproval({
  plan,
  pending,
  onApprove,
}: {
  plan: Plan;
  pending: boolean;
  onApprove: () => void;
}) {
  const { t, i18n } = useTranslation();
  const [read, setRead] = useState(false);
  const date = (value: string | null) =>
    value === null
      ? t('campaignPlan.noEnd')
      : new Date(value).toLocaleDateString(localeTag(i18n.language));
  const audience = [
    (plan.targeting.countries ?? []).join(', '),
    plan.targeting.age_min !== null && plan.targeting.age_max !== null
      ? `${String(plan.targeting.age_min)}–${String(plan.targeting.age_max)}`
      : null,
    (plan.targeting.languages ?? []).join(', '),
  ]
    .filter((part) => part !== null && part !== '')
    .join(' · ');

  return (
    <section
      aria-labelledby="activation-approval"
      className="border-warning flex flex-col gap-3 rounded-2xl border-2 p-4"
    >
      <h3 id="activation-approval" className="text-content text-base font-semibold">
        {t('activation.title')}
      </h3>
      <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        {(
          [
            [
              'accounts',
              plan.targets
                .map((target) => `${providerName(target.provider)} · ${target.name}`)
                .join(', '),
            ],
            [
              'budget',
              `${formatMinor(plan.budget_minor, plan.currency, i18n.language)} · ${t(`campaignPlan.${plan.budget_type}`)}`,
            ],
            ['schedule', `${date(plan.starts_at)} → ${date(plan.ends_at)}`],
            ['conversion', plan.conversion?.name ?? '—'],
            ['tracking', plan.tracking_link?.name ?? plan.destination ?? '—'],
            ['audience', audience === '' ? '—' : audience],
          ] as const
        ).map(([key, value]) => (
          <div key={key} className="flex min-w-0 flex-col">
            <dt className="text-content-tertiary">{t(`activation.${key}`)}</dt>
            <dd className="text-content break-words">{value}</dd>
          </div>
        ))}
      </dl>
      <label className="flex min-h-11 items-start gap-3 text-sm">
        <input
          type="checkbox"
          checked={read}
          onChange={(event) => setRead(event.target.checked)}
          className="mt-1"
        />
        <span className="text-content">{t('activation.confirm')}</span>
      </label>
      <Button className="self-start" disabled={!read || pending} onClick={onApprove}>
        {t('activation.approve')}
      </Button>
    </section>
  );
}
