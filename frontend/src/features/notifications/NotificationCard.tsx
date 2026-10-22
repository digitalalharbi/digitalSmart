import {
  Activity,
  BadgeCheck,
  FileChartColumn,
  Images,
  Landmark,
  Link2Off,
  ListChecks,
  Radar,
  X,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';
import { cn } from '@/shared/lib/cn';
import { formatMinor, formatNumber, localeTag } from '@/shared/lib/format';
import { Badge } from '@/shared/ui/badge';
import type {
  NotificationCategoryValue,
  NotificationItem,
  NotificationSeverityValue,
} from './types';

const CATEGORY_ICON: Record<NotificationCategoryValue, typeof Activity> = {
  performance: Activity,
  budget: Landmark,
  creative: Images,
  tracking: Radar,
  approvals: BadgeCheck,
  reports: FileChartColumn,
  work: ListChecks,
};

const SEVERITY_CLASS: Record<NotificationSeverityValue, string> = {
  critical: 'bg-danger-surface text-danger',
  attention: 'bg-warning-surface text-warning',
  success: 'bg-success-surface text-success',
  info: 'bg-surface-sunken text-content-secondary',
};

type Figure = { value: number | null; currency?: string | null; change_ratio?: number | null };

function isFigure(value: unknown): value is Figure {
  return typeof value === 'object' && value !== null && 'value' in value;
}

/**
 * The figures a card draws, read from the payload the server stored with the notification.
 *
 * Two shapes: an alert carries `before` and `after` (a ROAS of 3.8x became 2.9x); a digest carries headline
 * figures, each with its change. Nothing is computed here — a change is shown only where the server published one.
 */
function figures(item: NotificationItem, locale: string): { label: string; text: string }[] {
  const payload = item.payload ?? {};
  const percent = new Intl.NumberFormat(localeTag(locale), {
    style: 'percent',
    maximumFractionDigits: 0,
    signDisplay: 'exceptZero',
  });
  const out: { label: string; text: string }[] = [];

  if (typeof payload['before'] === 'number' && typeof payload['after'] === 'number') {
    out.push({
      label: '',
      text: `${formatNumber(payload['before'], locale, 1)} → ${formatNumber(payload['after'], locale, 1)}`,
    });
  }

  if (typeof payload['change_ratio'] === 'number') {
    out.push({ label: '', text: percent.format(payload['change_ratio']) });
  }

  for (const key of ['spend', 'roas', 'cpa', 'results'] as const) {
    const figure = payload[key];

    if (!isFigure(figure) || figure.value === null) {
      continue;
    }

    const text =
      key === 'spend' && typeof figure.currency === 'string'
        ? formatMinor(figure.value, figure.currency, locale)
        : formatNumber(figure.value, locale, key === 'results' ? 0 : 1);
    const change =
      typeof figure.change_ratio === 'number' ? ` ${percent.format(figure.change_ratio)}` : '';

    out.push({ label: key.toUpperCase(), text: `${text}${change}` });
  }

  return out.slice(0, 4);
}

function relative(iso: string, locale: string): string {
  const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
  const format = new Intl.RelativeTimeFormat(localeTag(locale), { numeric: 'auto' });
  const abs = Math.abs(seconds);

  if (abs < 3600) {
    return format.format(Math.round(seconds / 60), 'minute');
  }

  if (abs < 86_400) {
    return format.format(Math.round(seconds / 3600), 'hour');
  }

  return format.format(Math.round(seconds / 86_400), 'day');
}

/**
 * One notification: what changed, by how much, about what, and the one thing to do about it.
 *
 * The icon says which tab; the pill says how much it matters, in the server's own word; the figures are the
 * server's own payload; the scope line names the client or project where the payload carries one. The sentence
 * is the one that was announced. There is no paragraph.
 */
export function NotificationCard({
  item,
  onOpen,
  onRead,
  onDismiss,
}: {
  item: NotificationItem;
  onOpen: () => void;
  onRead: () => void;
  onDismiss: () => void;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const Icon = CATEGORY_ICON[item.category] ?? Activity;
  const shown = figures(item, locale);
  const payload = item.payload ?? {};
  const scope = [payload['client'], payload['project'], payload['best_platform']]
    .filter((value): value is string => typeof value === 'string' && value !== '')
    .slice(0, 2)
    .join(' · ');

  return (
    <article
      className={cn(
        'bg-surface border-line-subtle flex gap-3 rounded-2xl border p-4 shadow-xs',
        item.is_unread && 'border-accent/40',
      )}
    >
      <span
        aria-hidden
        className={cn(
          'grid size-9 shrink-0 place-items-center rounded-full',
          SEVERITY_CLASS[item.severity],
        )}
      >
        <Icon className="size-4" />
      </span>

      <div className="flex min-w-0 flex-1 flex-col gap-2">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div className="flex min-w-0 flex-col gap-0.5">
            <span className="text-content text-sm font-medium" dir="auto">
              {item.title}
            </span>
            <span className="text-content-tertiary text-xs" dir="auto">
              {scope !== ''
                ? scope
                : /* The title is said once, above; a subject that merely repeats it gives way to the kind. */
                  item.subject.label !== null && item.subject.label !== item.title
                  ? item.subject.label
                  : item.kind_label}
              {item.actor_name === null ? '' : ` · ${item.actor_name}`}
              {' · '}
              <time dateTime={item.created_at}>{relative(item.created_at, locale)}</time>
            </span>
          </div>
          <span className="flex flex-wrap items-center gap-1.5">
            {item.severity === 'info' ? null : (
              <span
                className={cn(
                  'rounded-full px-2 py-0.5 text-xs font-medium',
                  SEVERITY_CLASS[item.severity],
                )}
              >
                {t(`notifications.severity.${item.severity}`)}
              </span>
            )}
            {item.is_waiting ? (
              <Badge tone="warning">{t('notifications.waiting')}</Badge>
            ) : item.is_unread ? (
              <Badge tone="neutral">{t('notifications.unread')}</Badge>
            ) : null}
          </span>
        </div>

        {shown.length === 0 ? null : (
          <dl className="flex flex-wrap gap-x-4 gap-y-1">
            {shown.map((figure, index) => (
              <div key={index} className="flex items-baseline gap-1.5">
                {figure.label === '' ? null : (
                  <dt className="text-content-tertiary ds-overline">{figure.label}</dt>
                )}
                <dd className="ds-numeric text-content text-base font-semibold">
                  <bdi dir="ltr">{figure.text}</bdi>
                </dd>
              </div>
            ))}
          </dl>
        )}

        {item.body === null ? null : (
          <p className="text-content-secondary text-sm" dir="auto">
            {item.body}
          </p>
        )}

        {(item.deliveries ?? []).length === 0 ? null : (
          <ul className="flex flex-wrap gap-1.5" aria-label={t('notifications.delivery.label')}>
            {(item.deliveries ?? []).map((delivery) => (
              <li
                key={delivery.channel}
                className="bg-surface-sunken text-content-secondary rounded-full px-2 py-0.5 text-xs"
                title={delivery.detail ?? undefined}
              >
                {t(`notifications.settings.channel.${delivery.channel}`)} ·{' '}
                {t(`notifications.delivery.status.${delivery.status}`)}
              </li>
            ))}
          </ul>
        )}

        <div className="flex flex-wrap items-center gap-3">
          {item.subject.exists ? (
            <Link
              to={item.subject.path}
              className="text-content-brand text-sm hover:underline"
              onClick={onOpen}
            >
              {t('notifications.open', { subject: item.subject.type_label })}
            </Link>
          ) : (
            <span className="text-content-tertiary inline-flex items-center gap-1.5 text-sm">
              <Link2Off aria-hidden className="size-3.5" />
              {t('notifications.subjectGone', { subject: item.subject.type_label })}
            </span>
          )}

          {item.is_unread ? (
            <button
              type="button"
              onClick={onRead}
              className="text-content-tertiary hover:text-content text-sm"
            >
              {t('notifications.markRead')}
            </button>
          ) : null}

          <button
            type="button"
            onClick={onDismiss}
            aria-label={`${t('notifications.dismiss')} — ${item.title}`}
            className="text-content-tertiary hover:text-content ms-auto inline-flex items-center gap-1 text-sm"
          >
            <X aria-hidden className="size-3.5" />
            {t('notifications.dismiss')}
          </button>
        </div>
      </div>
    </article>
  );
}
