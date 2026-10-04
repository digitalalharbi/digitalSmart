import { useEffect, useRef } from 'react';
import { useLocation } from 'react-router';
import { useTranslation } from 'react-i18next';
import { X } from 'lucide-react';
import { Button } from '@/shared/ui/button';
import { useUiStore } from '@/shared/store/ui-store';
import { Sidebar } from './Sidebar';

/**
 * Navigation drawer for viewports below `lg`, where the rail is hidden.
 *
 * Closes on Escape, on backdrop click and whenever the route changes, and
 * locks background scroll while open.
 */
export function MobileNavDrawer() {
  const { t } = useTranslation();
  const isOpen = useUiStore((state) => state.isMobileNavOpen);
  const setOpen = useUiStore((state) => state.setMobileNavOpen);
  const location = useLocation();
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const lastPathRef = useRef(location.pathname);

  // Navigating away should never leave the drawer covering the page. Compare
  // against the previous path so mounting does not count as a navigation.
  useEffect(() => {
    if (lastPathRef.current !== location.pathname) {
      lastPathRef.current = location.pathname;
      setOpen(false);
    }
  }, [location.pathname, setOpen]);

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false);
      }
    };

    document.addEventListener('keydown', onKeyDown);
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    closeButtonRef.current?.focus();

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = previousOverflow;
    };
  }, [isOpen, setOpen]);

  if (!isOpen) {
    return null;
  }

  return (
    <div className="fixed inset-0 z-40 lg:hidden">
      <button
        type="button"
        aria-label={t('actions.closeNavigation')}
        onClick={() => setOpen(false)}
        className="bg-overlay animate-in fade-in absolute inset-0 duration-[var(--ds-duration-fast)]"
      />

      <div
        role="dialog"
        aria-modal="true"
        aria-label={t('a11y.mainNavigation')}
        className="bg-surface animate-in slide-in-from-start relative h-full w-[17rem] max-w-[85vw] shadow-lg duration-[var(--ds-duration-base)]"
      >
        <Button
          ref={closeButtonRef}
          variant="ghost"
          size="icon"
          onClick={() => setOpen(false)}
          aria-label={t('actions.closeNavigation')}
          className="absolute end-2 top-3 z-10"
        >
          <X />
        </Button>
        <Sidebar />
      </div>
    </div>
  );
}
