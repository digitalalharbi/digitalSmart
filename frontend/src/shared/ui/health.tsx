import {
  AlertTriangle,
  CircleCheck,
  CircleDashed,
  CircleHelp,
  CircleSlash,
  Clock,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import type { HealthState } from '@/shared/ui/health.model';

const ICON = {
  healthy: CircleCheck,
  watch: Clock,
  attention: AlertTriangle,
  critical: CircleSlash,
  inactive: CircleDashed,
  unknown: CircleHelp,
} as const;

const TONE = {
  healthy: 'bg-success-surface text-success',
  watch: 'bg-warning-surface text-warning',
  attention: 'bg-warning-surface text-warning',
  critical: 'bg-danger-surface text-danger',
  inactive: 'bg-surface-sunken text-content-tertiary border border-line-subtle',
  unknown: 'bg-surface-sunken text-content-secondary border border-line-subtle',
} as const;

/**
 * The state, as a pill.
 *
 * Never colour alone: every state carries its own glyph and its own word, so a reader who cannot separate
 * amber from red — or who is reading a printed report — loses nothing.
 */
export function HealthPill({
  state,
  detail,
  className,
}: {
  state: HealthState;
  /** A few words on why, where the state alone would leave somebody guessing. */
  detail?: string | undefined;
  className?: string | undefined;
}) {
  const { t } = useTranslation();
  const Icon = ICON[state];

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs leading-none font-medium',
        TONE[state],
        className,
      )}
      title={detail}
    >
      <Icon aria-hidden className="size-3.5" />
      {t(`health.${state}`)}
      {detail === undefined ? null : <span className="sr-only">{detail}</span>}
    </span>
  );
}

/** The state as a bare dot, for a dense row where the word is already in the column beside it. */
export function HealthDot({
  state,
  className,
}: {
  state: HealthState;
  className?: string | undefined;
}) {
  const { t } = useTranslation();

  return (
    <span
      role="img"
      aria-label={t(`health.${state}`)}
      className={cn(
        'inline-block size-2 shrink-0 rounded-full',
        state === 'healthy' && 'bg-success',
        (state === 'watch' || state === 'attention') && 'bg-warning',
        state === 'critical' && 'bg-danger',
        (state === 'inactive' || state === 'unknown') && 'bg-content-tertiary',
        className,
      )}
    />
  );
}
