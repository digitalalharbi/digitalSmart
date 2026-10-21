import { ArrowLeft, ArrowRight, Maximize2, Minimize2, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState, type PointerEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { cn } from '@/shared/lib/cn';
import { formatChange, formatMetric, MISSING } from '@/shared/metrics';
import { SourceBadge } from '@/shared/ui/source-badge';
import type { MetricSourceValue } from '@/shared/metrics';
import { ReportHighlights } from './ReportHighlights';
import { ReportSectionView } from './ReportSectionView';
import { buildSlides, stepFor, swipeStep, type Slide } from './slides';
import type { RenderedReport } from './types';

/**
 * A report as a talk: full screen, one story at a time, large figures, no application around it.
 *
 * Built from the same report and the same section views as the page, so a number on a screen here is the number on the
 * page — a presentation that re-derived anything would be one more place to be wrong in front of a client. It adds
 * only the deck: the order, the keyboard and swipe, the fullscreen, the closing screen.
 *
 * Keyboard and touch follow the page's direction: in Arabic the arrows are mirrored and a swipe goes the other way, as
 * the pages of a book do. Escape leaves, and focus goes back to what opened it.
 */
export function ReportPresentation({
  report,
  onClose,
}: {
  report: RenderedReport;
  onClose: () => void;
}) {
  const { t, i18n } = useTranslation();
  const rtl = i18n.dir() === 'rtl';
  const slides = buildSlides(report);
  const [index, setIndex] = useState(0);
  const [fullscreen, setFullscreen] = useState(false);
  const root = useRef<HTMLDivElement | null>(null);
  const opener = useRef<HTMLElement | null>(null);
  const touch = useRef<{ x: number; y: number } | null>(null);

  const go = useCallback(
    (to: number) => setIndex(Math.min(slides.length - 1, Math.max(0, to))),
    [slides.length],
  );

  /* The page behind it does not scroll, and focus returns to whatever opened the deck. */
  useEffect(() => {
    opener.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    root.current?.focus();

    return () => {
      document.body.style.overflow = previous;
      opener.current?.focus();
    };
  }, []);

  useEffect(() => {
    const sync = () => setFullscreen(document.fullscreenElement !== null);
    document.addEventListener('fullscreenchange', sync);

    return () => document.removeEventListener('fullscreenchange', sync);
  }, []);

  const toggleFullscreen = (): void => {
    if (document.fullscreenElement !== null) {
      void document.exitFullscreen();
    } else {
      void root.current?.requestFullscreen?.();
    }
  };

  const slide = slides[index] as Slide;
  const title = slideTitle(slide, report, t);

  return (
    <div
      ref={root}
      role="dialog"
      aria-modal="true"
      aria-label={t('reports.present.label', { name: report.name })}
      tabIndex={-1}
      className="bg-canvas fixed inset-0 z-50 flex flex-col outline-none"
      onKeyDown={(event) => {
        if (event.key === 'Escape') {
          onClose();

          return;
        }

        if (event.key === 'Home') {
          go(0);

          return;
        }

        if (event.key === 'End') {
          go(slides.length - 1);

          return;
        }

        if (event.key === 'f' || event.key === 'F') {
          toggleFullscreen();

          return;
        }

        const step = stepFor(event.key, rtl);

        if (step !== 0) {
          event.preventDefault();
          go(index + step);
        }
      }}
    >
      <header className="flex items-center gap-3 px-4 py-3">
        <button
          type="button"
          onClick={onClose}
          aria-label={t('reports.present.close')}
          className="hover:bg-surface-sunken grid size-9 place-items-center rounded-full"
        >
          <X aria-hidden className="size-5" />
        </button>
        <p className="text-content-secondary ds-numeric text-sm" aria-hidden>
          {new Intl.NumberFormat(i18n.language).format(index + 1)} /{' '}
          {new Intl.NumberFormat(i18n.language).format(slides.length)}
        </p>
        <div className="ms-auto flex items-center gap-1">
          <button
            type="button"
            onClick={toggleFullscreen}
            aria-label={t('reports.present.fullscreen')}
            aria-pressed={fullscreen}
            className="hover:bg-surface-sunken grid size-9 place-items-center rounded-full"
          >
            {fullscreen ? (
              <Minimize2 aria-hidden className="size-4" />
            ) : (
              <Maximize2 aria-hidden className="size-4" />
            )}
          </button>
        </div>
      </header>

      {/* Announced as the slide changes: the figures are in the slide, the title is what a screen reader needs first. */}
      <p className="sr-only" role="status" aria-live="polite">
        {t('reports.present.position', { n: index + 1, total: slides.length, title })}
      </p>

      <div
        className="min-h-0 flex-1 overflow-y-auto px-4 pb-4 sm:px-10"
        onPointerDown={(event: PointerEvent) => {
          touch.current = { x: event.clientX, y: event.clientY };
        }}
        onPointerUp={(event: PointerEvent) => {
          const start = touch.current;
          touch.current = null;

          if (start !== null) {
            const step = swipeStep(event.clientX - start.x, event.clientY - start.y, rtl);

            if (step !== 0) {
              go(index + step);
            }
          }
        }}
      >
        <div className="mx-auto flex min-h-full w-full max-w-5xl flex-col justify-center gap-6 py-4">
          <SlideBody slide={slide} report={report} title={title} />
        </div>
      </div>

      <footer className="flex items-center justify-between gap-3 px-4 py-3">
        <button
          type="button"
          onClick={() => go(index - 1)}
          disabled={index === 0}
          aria-label={t('reports.present.previous')}
          className="hover:bg-surface-sunken grid size-11 place-items-center rounded-full disabled:opacity-30"
        >
          <ArrowLeft aria-hidden className="size-5 rtl:rotate-180" />
        </button>
        <ol aria-hidden className="flex flex-1 justify-center gap-1.5">
          {slides.map((entry, position) => (
            <li
              key={entry.key}
              className={cn(
                'h-1.5 rounded-full transition-all',
                position === index ? 'bg-accent-strong w-6' : 'bg-line w-1.5',
              )}
            />
          ))}
        </ol>
        <button
          type="button"
          onClick={() => go(index + 1)}
          disabled={index === slides.length - 1}
          aria-label={t('reports.present.next')}
          className="hover:bg-surface-sunken grid size-11 place-items-center rounded-full disabled:opacity-30"
        >
          <ArrowRight aria-hidden className="size-5 rtl:rotate-180" />
        </button>
      </footer>
    </div>
  );
}

function slideTitle(slide: Slide, report: RenderedReport, t: (key: string) => string): string {
  switch (slide.kind) {
    case 'cover':
      return report.name;
    case 'kpis':
    case 'section':
      return slide.section.title;
    case 'highlight':
      return t(`reports.present.part.${slide.part}`);
    case 'status':
      return t('reports.present.status');
  }
}

function SlideBody({
  slide,
  report,
  title,
}: {
  slide: Slide;
  report: RenderedReport;
  title: string;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;

  if (slide.kind === 'cover') {
    return (
      <div className="flex flex-col items-start gap-5">
        <span
          aria-hidden
          className="bg-surface-sunken text-content-secondary inline-flex size-16 items-center justify-center rounded-3xl text-xl font-semibold"
        >
          {(report.client_name ?? report.name).slice(0, 2).toUpperCase()}
        </span>
        <h2 className="text-content text-4xl font-semibold sm:text-6xl" dir="auto">
          {report.name}
        </h2>
        {report.client_name === null ? null : (
          <p className="text-content-secondary text-xl" dir="auto">
            {report.client_name}
          </p>
        )}
        <p className="text-content-secondary ds-numeric text-lg">
          <bdi dir="ltr">
            {report.period.from} – {report.period.until}
          </bdi>
        </p>
        <span className="bg-surface-sunken text-content rounded-full px-3 py-1 text-sm font-medium">
          {report.mode_label}
        </span>
      </div>
    );
  }

  if (slide.kind === 'kpis') {
    const values = slide.section.metrics.flatMap((metric) => {
      const value = slide.section.totals?.values[metric];

      return value === undefined ? [] : [value];
    });

    return (
      <>
        <h2 className="text-content-secondary text-xl font-medium" dir="auto">
          {title}
        </h2>
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {values.map((value) => {
            const change = formatChange(value, locale);

            return (
              <li
                key={value.metric}
                className="bg-surface flex min-w-0 flex-col gap-2 rounded-3xl p-6 shadow-xs"
              >
                <span className="text-content-tertiary ds-overline">{value.label}</span>
                <span
                  className={cn(
                    'ds-figure',
                    value.value === null
                      ? 'text-content-tertiary text-3xl'
                      : 'text-content text-4xl sm:text-5xl',
                  )}
                  /* Written short on a screen meant to be read from across a room; the whole figure is in its title. */
                  title={value.value === null ? undefined : formatMetric(value, locale)}
                >
                  {value.value === null ? MISSING : formatMetric(value, locale, true)}
                </span>
                {change === null ? null : (
                  <span
                    className={cn(
                      'ds-numeric text-lg font-medium',
                      value.change_is_good === true && 'text-success',
                      value.change_is_good === false && 'text-warning',
                      value.change_is_good === null && 'text-content-secondary',
                    )}
                  >
                    <bdi dir="ltr">{change}</bdi>
                  </span>
                )}
              </li>
            );
          })}
        </ul>
      </>
    );
  }

  if (slide.kind === 'section') {
    return <ReportSectionView section={slide.section} />;
  }

  if (slide.kind === 'highlight') {
    return (
      <>
        <h2 className="text-content-secondary text-xl font-medium">{title}</h2>
        <ReportHighlights report={report} parts={[slide.part]} />
      </>
    );
  }

  const caveats = [...new Set(report.sections.flatMap((section) => section.caveats))].slice(0, 5);

  return (
    <>
      <h2 className="text-content-secondary text-xl font-medium">{title}</h2>
      <div className="bg-surface flex flex-col gap-4 rounded-3xl p-6 shadow-xs">
        <div className="flex flex-wrap items-center gap-2">
          <SourceBadge source={report.source as MetricSourceValue} />
          <span className="bg-surface-sunken text-content rounded-full px-3 py-1 text-sm font-medium">
            {report.mode_label}
          </span>
          <span className="text-content-tertiary ds-numeric text-sm">
            {t('reports.view.renderedAt', {
              at: new Date(report.rendered_at).toLocaleString(locale),
            })}
          </span>
        </div>
        {caveats.length === 0 ? null : (
          <ul className="text-content-secondary flex list-disc flex-col gap-1.5 ps-5 text-sm">
            {caveats.map((caveat) => (
              <li key={caveat}>{caveat}</li>
            ))}
          </ul>
        )}
      </div>
    </>
  );
}
