import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { cn } from '@/shared/lib/cn';

export type Tone = 'critical' | 'attention' | 'watch' | 'neutral';

const TONE: Record<Tone, string> = {
  critical: 'bg-danger-surface text-danger',
  attention: 'bg-warning-surface text-warning',
  watch: 'bg-warning-surface text-warning',
  neutral: 'bg-surface-sunken text-content-secondary',
};

/**
 * One decision, ready to take.
 *
 * What kind of thing it is, whose it is, the single figure that matters, a picture of the change, and the buttons.
 * Nothing else is on the face of it: the explanation, the proposal's fields and the history are behind `details`,
 * which is a link, not a section. The system has already done the reading; the person is asked only to decide.
 */
export function DecisionCard({
  icon,
  type,
  tone = 'neutral',
  scope,
  headline,
  byline,
  visual,
  actions,
  details,
  className,
}: {
  icon: ReactNode;
  /** "Budget", "Creative", "Campaign" — the card's kind, in one word. */
  type: string;
  tone?: Tone;
  /** Client and project, already joined. */
  scope?: string | null | undefined;
  headline: string;
  /** Who prepared it, when it was not a person: "Prepared by the Analyst". */
  byline?: string | undefined;
  /** The picture of the change: a before/after, a metric against its target, a preview. */
  visual?: ReactNode;
  /** The buttons: the primary first. */
  actions: ReactNode;
  details?: { to: string; label: string } | undefined;
  className?: string | undefined;
}) {
  return (
    <article
      aria-label={`${type}: ${headline}`}
      className={cn('bg-surface flex w-full flex-col gap-4 rounded-3xl p-5 shadow-xs', className)}
    >
      <header className="flex items-start justify-between gap-3">
        <span
          className={cn(
            'inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold',
            TONE[tone],
          )}
        >
          <span aria-hidden className="grid size-4 place-items-center [&_svg]:size-4">
            {icon}
          </span>
          {type}
        </span>
        {details === undefined ? null : (
          <Link to={details.to} className="text-content-tertiary hover:text-content text-xs">
            {details.label}
          </Link>
        )}
      </header>

      <div className="flex min-w-0 flex-col gap-1">
        {scope === undefined || scope === null || scope === '' ? null : (
          <p className="text-content-tertiary truncate text-xs" dir="auto">
            {scope}
          </p>
        )}
        <h3 className="text-content text-base leading-snug font-semibold" dir="auto">
          {headline}
        </h3>
        {byline === undefined ? null : (
          <p className="text-content-brand flex items-center gap-1 text-xs font-medium">
            <span aria-hidden>✦</span>
            {byline}
          </p>
        )}
      </div>

      {visual}

      <div className="mt-auto flex flex-wrap gap-2">{actions}</div>
    </article>
  );
}

/** Before and after, side by side, with the difference between them: the whole explanation of a budget change. */
export function BeforeAfter({
  before,
  after,
  difference,
  beforeLabel,
  afterLabel,
  positive,
}: {
  before: string;
  after: string;
  difference: string;
  beforeLabel: string;
  afterLabel: string;
  /** Whether the change is an increase, which decides the colour of the difference — not whether it is good. */
  positive: boolean;
}) {
  return (
    <div className="bg-surface-sunken grid grid-cols-[1fr_auto_1fr] items-center gap-3 rounded-2xl p-4">
      <div className="flex min-w-0 flex-col">
        <span className="text-content-tertiary text-xs">{beforeLabel}</span>
        <span className="ds-numeric text-content-secondary text-base font-semibold break-words">
          {before}
        </span>
      </div>
      <span
        className={cn(
          'ds-numeric rounded-full px-2.5 py-1 text-xs font-semibold',
          positive ? 'bg-accent-subtle text-content-brand' : 'bg-surface-active text-content',
        )}
      >
        {difference}
      </span>
      <div className="flex min-w-0 flex-col items-end text-end">
        <span className="text-content-tertiary text-xs">{afterLabel}</span>
        <span className="ds-numeric text-content text-base font-semibold break-words">{after}</span>
      </div>
    </div>
  );
}

/** A figure against its target, as a bar with the target marked — the whole explanation of an underperforming campaign. */
export function AgainstTarget({
  label,
  value,
  target,
  targetLabel,
  ratio,
}: {
  label: string;
  value: string;
  target: string;
  targetLabel: string;
  /** Value as a share of target, 0–1+. */
  ratio: number;
}) {
  const filled = Math.max(2, Math.min(100, ratio * 100));

  return (
    <div className="bg-surface-sunken flex flex-col gap-2 rounded-2xl p-4">
      <div className="flex items-baseline justify-between gap-3">
        <span className="text-content-tertiary text-xs">{label}</span>
        <span className="ds-numeric text-content text-lg font-semibold">{value}</span>
      </div>
      <span aria-hidden className="bg-surface-active relative block h-2 rounded-full">
        <span
          className="bg-accent block h-full rounded-full"
          style={{ width: `${String(filled)}%` }}
        />
      </span>
      <span className="text-content-tertiary text-xs">
        {targetLabel} <span className="ds-numeric text-content-secondary">{target}</span>
      </span>
    </div>
  );
}
