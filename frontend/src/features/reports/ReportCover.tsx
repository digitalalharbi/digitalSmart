import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';

/**
 * The first page of a report, small.
 *
 * A cover, not a chart: it carries who it is for, what it is called, the period and how many sections it holds,
 * drawn as the title page of a deck would be. It shows no figures, so it cannot be mistaken for the report's
 * content or go stale — the real numbers appear when the report is opened.
 */
export function ReportCover({
  name,
  client,
  window,
  sections,
  clientSafe,
}: {
  name: string;
  client: string;
  window: string;
  sections: number;
  clientSafe: boolean;
}) {
  const { t } = useTranslation();
  const bars = Math.min(6, Math.max(1, sections));

  return (
    <span
      aria-hidden
      className={cn(
        'border-line-subtle bg-surface-sunken flex aspect-[16/9] w-full flex-col justify-between overflow-hidden rounded-xl border p-3',
        clientSafe && 'bg-accent-subtle',
      )}
    >
      <span
        className="text-content-tertiary text-[10px] font-medium tracking-wide uppercase"
        dir="auto"
      >
        {client}
      </span>
      <span className="text-content line-clamp-2 text-sm leading-tight font-semibold" dir="auto">
        {name}
      </span>
      <span className="flex items-end justify-between gap-2">
        <span className="flex h-5 items-end gap-1">
          {Array.from({ length: bars }, (_, index) => (
            <span
              key={index}
              className="bg-accent/40 w-1.5 rounded-sm"
              style={{ height: `${String(30 + ((index * 37) % 70))}%` }}
            />
          ))}
        </span>
        <span className="text-content-tertiary text-[10px]">
          {window} · {t('reports.cover.sections', { count: sections })}
        </span>
      </span>
    </span>
  );
}
