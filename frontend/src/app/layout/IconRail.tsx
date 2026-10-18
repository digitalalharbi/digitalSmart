import { useState, type FocusEvent, type MouseEvent } from 'react';
import { NavLink } from 'react-router';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { NavIcon } from './NavIcon';
import { useAreas } from './useAreas';

/**
 * The compact vertical rail: icons only, on a floating white pill, beside the content.
 *
 * Every destination the top pill does not carry is here, grouped by section with a hairline between groups, and the
 * system group is pinned to the bottom the way the reference separates settings and support from the rest. An icon
 * names itself two ways — its accessible name, and a tooltip that shows on hover *and* on keyboard focus, so the
 * rail is not a guessing game for someone who cannot hover.
 */
export function IconRail() {
  const { t } = useTranslation();
  const { more, current } = useAreas();

  /*
   * One tooltip for the whole rail, positioned from the hovered icon's own rectangle. The icon list scrolls, and a
   * scrolling container clips anything absolutely positioned inside it — a tooltip drawn per icon would be cut off
   * at the rail's edge, which is the one place it has to appear.
   */
  const [tip, setTip] = useState<{ label: string; top: number; edge: number; rtl: boolean } | null>(
    null,
  );

  const show = (event: MouseEvent<HTMLElement> | FocusEvent<HTMLElement>, label: string) => {
    const box = event.currentTarget.getBoundingClientRect();
    const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';

    setTip({
      label,
      top: box.top + box.height / 2,
      edge: rtl ? window.innerWidth - box.left + 12 : box.right + 12,
      rtl,
    });
  };

  const pinnedKeys = ['integrations', 'settings'];
  const scrolling = more.filter((area) => !pinnedKeys.includes(area.key));
  const pinned = more.filter((area) => pinnedKeys.includes(area.key));

  const renderArea = (area: (typeof more)[number]) => (
    <NavLink
      key={area.key}
      to={area.home}
      aria-label={t(area.labelKey)}
      aria-current={current === area.key ? 'page' : undefined}
      onMouseEnter={(event) => show(event, t(area.labelKey))}
      onFocus={(event) => show(event, t(area.labelKey))}
      onMouseLeave={() => setTip(null)}
      onBlur={() => setTip(null)}
      className={cn(
        'grid size-10 place-items-center rounded-full',
        'ease-standard transition-colors duration-[var(--ds-duration-fast)]',
        current === area.key
          ? 'bg-action text-action-contrast'
          : 'text-content-secondary hover:bg-surface-hover hover:text-content',
      )}
    >
      <NavIcon name={area.icon} className="size-[1.125rem]" />
    </NavLink>
  );

  if (more.length === 0) {
    return null;
  }

  return (
    <>
      {tip === null ? null : (
        <span
          aria-hidden="true"
          style={{ top: tip.top, ...(tip.rtl ? { right: tip.edge } : { left: tip.edge }) }}
          className="bg-action text-action-contrast pointer-events-none fixed z-50 -translate-y-1/2 rounded-full px-3 py-1.5 text-xs font-medium whitespace-nowrap shadow-md"
        >
          {tip.label}
        </span>
      )}
      <nav
        aria-label={t('a11y.sectionsNavigation')}
        className="bg-surface flex w-14 flex-col items-center gap-3 rounded-full px-2 py-3 shadow-xs"
      >
        <div className="flex flex-col items-center gap-1">{scrolling.map(renderArea)}</div>

        {pinned.length > 0 ? (
          <div className="border-line-subtle flex flex-col items-center gap-1 border-t pt-3">
            {pinned.map(renderArea)}
          </div>
        ) : null}
      </nav>
    </>
  );
}
