import { Briefcase, LayoutGrid, Megaphone, type LucideIcon } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { ACCESS_NEEDS, type AccessNeed } from './api';

const ICON: Record<AccessNeed, LucideIcon> = {
  self_managed: LayoutGrid,
  agency_clients: Briefcase,
  ad_services: Megaphone,
};

/**
 * "What do you want to achieve?" — three ways a visitor relates to the product, drawn as cards.
 *
 * One choice, always. It says who is asking so the reply can start in the right place; it grants nothing.
 */
export function NeedPicker({
  value,
  onChange,
  name,
}: {
  value: AccessNeed;
  onChange: (need: AccessNeed) => void;
  name: string;
}) {
  const { t } = useTranslation();

  return (
    <div role="radiogroup" aria-label={name} className="flex flex-col gap-2.5">
      {ACCESS_NEEDS.map((need) => {
        const Icon = ICON[need];
        const selected = value === need;

        return (
          <button
            key={need}
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={() => onChange(need)}
            className={cn(
              'border-line flex min-h-14 w-full items-center gap-3 rounded-2xl border p-3 text-start transition-colors',
              'focus-visible:ring-accent/40 focus-visible:ring-2 focus-visible:outline-none',
              selected
                ? 'border-accent-strong bg-accent-subtle'
                : 'bg-surface-sunken hover:bg-surface-hover',
            )}
          >
            <span
              className={cn(
                'grid size-10 shrink-0 place-items-center rounded-xl',
                selected ? 'bg-accent-strong text-white' : 'bg-surface text-content-secondary',
              )}
            >
              <Icon aria-hidden className="size-5" />
            </span>
            <span className="min-w-0 flex-1">
              <span className="text-content block text-sm font-semibold">
                {t(`entry.needs.${need}.title`)}
              </span>
              <span className="text-content-secondary block text-xs leading-snug">
                {t(`entry.needs.${need}.line`)}
              </span>
            </span>
          </button>
        );
      })}
    </div>
  );
}
