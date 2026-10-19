import { useState, type HTMLAttributes } from 'react';
import { AlertTriangle, Info } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';

type AlertProps = HTMLAttributes<HTMLDivElement> & {
  tone?: 'danger' | 'warning' | 'info';
};

const TONES: Record<NonNullable<AlertProps['tone']>, string> = {
  danger: 'border-danger/30 bg-danger-surface text-content',
  warning: 'border-warning/30 bg-warning-surface text-content',
  info: 'border-info/30 bg-info-surface text-content',
};

/** A note longer than this is a paragraph, and is folded to two lines until somebody asks for the rest. */
const FOLD_AT = 110;

/**
 * A notice. A failure is always shown in full — somebody has to act on it. A *note* (information, or a caution about how
 * to read a figure) that runs long is folded to two lines with a "More" control: the text is all in the page, but it
 * no longer takes the screen from the figures it is about.
 */
export function Alert({ className, tone = 'info', children, ...props }: AlertProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const folds = tone !== 'danger' && typeof children === 'string' && children.length > FOLD_AT;

  if (!folds) {
    return (
      <div
        role="alert"
        className={cn('rounded-lg border px-4 py-3 text-sm', TONES[tone], className)}
        {...props}
      >
        {children}
      </div>
    );
  }

  const Icon = tone === 'warning' ? AlertTriangle : Info;

  return (
    <div
      role="alert"
      className={cn(
        'flex items-start gap-3 rounded-lg border px-4 py-3 text-sm',
        TONES[tone],
        className,
      )}
      {...props}
    >
      <Icon aria-hidden className="mt-0.5 size-4 shrink-0" />
      <p className={cn('min-w-0 flex-1', open ? '' : 'line-clamp-2')}>{children}</p>
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        className="text-content-secondary hover:text-content shrink-0 text-xs font-semibold underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-[var(--ds-focus-ring)] focus-visible:outline-none"
      >
        {t(open ? 'actions.less' : 'actions.more')}
      </button>
    </div>
  );
}
