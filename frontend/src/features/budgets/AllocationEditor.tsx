import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { Plus, Trash2 } from 'lucide-react';
import { useProvidersQuery } from '@/features/integrations';
import { Button } from '@/shared/ui/button';
import { Choice, ChoiceGroup } from '@/shared/ui/choice';
import { Input } from '@/shared/ui/input';
import { formatMinor } from '@/shared/lib/format';
import { toMinor } from '@/shared/lib/money';
import { cn } from '@/shared/lib/cn';
import { sumMinor } from './money';
import type { AllocationDraft, AllocationLevelValue } from './types';

const CHILD_LEVEL: Record<AllocationLevelValue, AllocationLevelValue | null> = {
  platform: 'campaign',
  campaign: 'ad_group',
  ad_group: null,
};

/**
 * The platforms worth allocating to, and their names, from the provider registry.
 *
 * Not a list in this file. The registry is the single source of truth for what a
 * provider is called and what its API can do, and a second copy here would drift the
 * day a provider is added — which is exactly what happened to the platform labels this
 * replaced.
 *
 * Filtered to providers whose API can report metrics, because an allocation that can
 * never be compared with spend is a number in a spreadsheet rather than a budget. A
 * provider that is not connected yet is still offered: a plan is written before the
 * accounts are wired up.
 */
function useAllocatableProviders(): Array<{ slug: string; label: string }> {
  const providers = useProvidersQuery();

  return useMemo(
    () =>
      (providers.data ?? [])
        .filter((row) => row.capabilities.some((capability) => capability.slug === 'metrics_sync'))
        .map((row) => ({ slug: row.provider, label: row.label })),
    [providers.data],
  );
}

type AllocationEditorProps = {
  allocations: AllocationDraft[];
  budgetMinor: number;
  currency: string;
  locale: string;
  disabled?: boolean;
  onChange: (allocations: AllocationDraft[]) => void;
};

/**
 * The allocation tree, edited in place.
 *
 * The remainder at every level is shown and **not** auto-filled. A control that spread
 * the leftover across children would be making a media decision on somebody's behalf and
 * calling it a convenience; showing it as an unallocated figure is how a planner
 * remembers there is a decision left to make.
 *
 * Over-allocation is shown here as a warning and refused by the server. The two are
 * deliberately both present: the warning is the fast feedback, the refusal is the rule.
 */
export function AllocationEditor({
  allocations,
  budgetMinor,
  currency,
  locale,
  disabled = false,
  onChange,
}: AllocationEditorProps) {
  const { t } = useTranslation();
  const providers = useAllocatableProviders();
  const allocated = sumMinor(allocations, currency);
  const remainder = budgetMinor - allocated;

  const addPlatform = () => {
    onChange([
      ...allocations,
      {
        key: nextKey(),
        level: 'platform',
        // The first platform the registry offers, rather than one named here.
        provider: providers[0]?.slug ?? '',
        label: t('budgets.allocations.newPlatform'),
        amount: '',
        campaign_id: null,
        ad_group_id: null,
        children: [],
      },
    ]);
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-content-secondary text-sm">
          {t('budgets.allocations.allocatedOf', {
            allocated: formatMinor(allocated, currency, locale),
            budget: formatMinor(budgetMinor, currency, locale),
          })}
        </p>
        <p
          className={cn(
            'text-xs tabular-nums',
            remainder < 0 ? 'text-danger' : 'text-content-tertiary',
          )}
        >
          {remainder < 0
            ? t('budgets.allocations.over', {
                amount: formatMinor(Math.abs(remainder), currency, locale),
              })
            : t('budgets.allocations.unallocated', {
                amount: formatMinor(remainder, currency, locale),
              })}
        </p>
      </div>

      <ul className="flex flex-col gap-3">
        {allocations.map((allocation, index) => (
          <li key={allocation.key}>
            <Node
              allocation={allocation}
              providers={providers}
              currency={currency}
              locale={locale}
              disabled={disabled}
              onChange={(next) => onChange(replaceAt(allocations, index, next))}
              onRemove={() => onChange(removeAt(allocations, index))}
            />
          </li>
        ))}
      </ul>

      <div>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={addPlatform}
          disabled={disabled || providers.length === 0}
        >
          <Plus className="size-4" aria-hidden="true" />
          {t('budgets.allocations.addPlatform')}
        </Button>
      </div>
    </div>
  );
}

function Node({
  allocation,
  providers,
  currency,
  locale,
  disabled,
  onChange,
  onRemove,
}: {
  allocation: AllocationDraft;
  providers: Array<{ slug: string; label: string }>;
  currency: string;
  locale: string;
  disabled: boolean;
  onChange: (next: AllocationDraft) => void;
  onRemove: () => void;
}) {
  const { t } = useTranslation();
  const childLevel = CHILD_LEVEL[allocation.level];
  const own = toMinor(allocation.amount, currency);
  const childTotal = sumMinor(allocation.children, currency);
  const remainder = own - childTotal;

  const addChild = () => {
    if (childLevel === null) {
      return;
    }

    onChange({
      ...allocation,
      children: [
        ...allocation.children,
        {
          key: nextKey(),
          level: childLevel,
          // Inherited, not chosen: a child on another platform would make its parent's
          // total a lie, and the server refuses it.
          provider: allocation.provider,
          label: t(`budgets.allocations.new.${childLevel}`),
          amount: '',
          campaign_id: null,
          ad_group_id: null,
          children: [],
        },
      ],
    });
  };

  return (
    <div
      className={cn(
        'border-line-subtle flex flex-col gap-3 rounded-lg border p-3',
        allocation.level === 'platform' ? 'bg-surface' : 'bg-surface-sunken',
      )}
    >
      <div className="flex flex-wrap items-start gap-3">
        <label className="flex min-w-40 flex-1 flex-col gap-1">
          <span className="text-content-tertiary text-xs font-medium">
            {t('budgets.allocations.label')}
          </span>
          <Input
            value={allocation.label}
            disabled={disabled}
            onChange={(event) => onChange({ ...allocation, label: event.target.value })}
          />
        </label>

        <label className="flex w-36 flex-col gap-1">
          <span className="text-content-tertiary text-xs font-medium">
            {t('budgets.allocations.amount', { currency })}
          </span>
          <Input
            inputMode="decimal"
            value={allocation.amount}
            disabled={disabled}
            onChange={(event) => onChange({ ...allocation, amount: event.target.value })}
          />
        </label>

        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={onRemove}
          disabled={disabled}
          aria-label={t('budgets.allocations.remove', { label: allocation.label })}
          className="mt-5"
        >
          <Trash2 className="size-4" aria-hidden="true" />
        </Button>
      </div>

      {allocation.level === 'platform' ? (
        <ChoiceGroup label={t('budgets.allocations.platform')}>
          <div className="flex flex-wrap gap-1.5">
            {providers.map((provider) => (
              <Choice
                key={provider.slug}
                isSelected={allocation.provider === provider.slug}
                disabled={disabled}
                onSelect={() =>
                  onChange({
                    ...allocation,
                    provider: provider.slug,
                    // The whole branch follows the platform, because the server refuses a
                    // child under a different one.
                    children: allocation.children.map((child) => ({
                      ...child,
                      provider: provider.slug,
                    })),
                  })
                }
              >
                {provider.label}
              </Choice>
            ))}
          </div>
        </ChoiceGroup>
      ) : null}

      {allocation.children.length === 0 ? null : (
        <>
          <p
            className={cn(
              'text-xs tabular-nums',
              remainder < 0 ? 'text-danger' : 'text-content-tertiary',
            )}
          >
            {remainder < 0
              ? t('budgets.allocations.childrenOver', {
                  amount: formatMinor(Math.abs(remainder), currency, locale),
                })
              : t('budgets.allocations.childrenUnallocated', {
                  amount: formatMinor(remainder, currency, locale),
                })}
          </p>
          <ul className="ms-4 flex flex-col gap-3">
            {allocation.children.map((child, index) => (
              <li key={child.key}>
                <Node
                  allocation={child}
                  providers={providers}
                  currency={currency}
                  locale={locale}
                  disabled={disabled}
                  onChange={(next) =>
                    onChange({
                      ...allocation,
                      children: replaceAt(allocation.children, index, next),
                    })
                  }
                  onRemove={() =>
                    onChange({ ...allocation, children: removeAt(allocation.children, index) })
                  }
                />
              </li>
            ))}
          </ul>
        </>
      )}

      {childLevel === null ? null : (
        <div>
          <Button type="button" variant="ghost" size="sm" onClick={addChild} disabled={disabled}>
            <Plus className="size-4" aria-hidden="true" />
            {t(`budgets.allocations.add.${childLevel}`)}
          </Button>
        </div>
      )}
    </div>
  );
}

function replaceAt<T>(items: T[], index: number, next: T): T[] {
  return items.map((item, position) => (position === index ? next : item));
}

function removeAt<T>(items: T[], index: number): T[] {
  return items.filter((_, position) => position !== index);
}

let counter = 0;

/** A key for the editing session only. The server assigns real ids on save. */
function nextKey(): string {
  counter += 1;

  return `allocation-${counter}`;
}
