import { campaignCards, funnelStages, insights, recommendations } from './highlights';
import type { CreativeSectionRow, RenderedReport, RenderedSection } from './types';

/**
 * The screens of a report, in the order a client reads it.
 *
 * One story per screen, and only stories the report can actually tell: a funnel screen exists only if the figures carry
 * the stages, an insights screen only if there is something that stands out. Nothing is padded to reach a shape. The
 * order is the report's own reading order — the headline figures, how they moved, who carried them, where the
 * audience was lost, then commerce and leads, then what stands out and what to look at — not the order the sections
 * happened to be saved in.
 */
export type Slide =
  | { key: string; kind: 'cover' }
  | { key: string; kind: 'kpis'; section: RenderedSection }
  | { key: string; kind: 'section'; section: RenderedSection }
  | {
      key: string;
      kind: 'creative';
      section: RenderedSection;
      row: CreativeSectionRow;
      /** Which creative of how many this section shows, for the "2 of 6" under the picture. */
      n: number;
      of: number;
    }
  | { key: string; kind: 'highlight'; part: 'funnel' | 'insights' | 'campaigns' | 'advice' }
  | { key: string; kind: 'status' };

/** Sections that carry nothing say so in the report; a screen of "nothing recorded" would be a dead end in a meeting. */
const hasContent = (section: RenderedSection): boolean => !section.is_empty;

export function buildSlides(report: RenderedReport): Slide[] {
  const live = report.sections.filter(hasContent);
  const of = (kind: RenderedSection['kind']): RenderedSection[] =>
    live.filter((section) => section.kind === kind);

  const slides: Slide[] = [{ key: 'cover', kind: 'cover' }];

  of('scorecard').forEach((section, index) =>
    slides.push({ key: `kpis-${String(index)}`, kind: 'kpis', section }),
  );
  of('trend').forEach((section, index) =>
    slides.push({ key: `trend-${String(index)}`, kind: 'section', section }),
  );
  [...of('breakdown'), ...of('table')].forEach((section, index) =>
    slides.push({ key: `rank-${String(index)}`, kind: 'section', section }),
  );

  if (campaignCards(report).top.length > 0) {
    slides.push({ key: 'campaigns', kind: 'highlight', part: 'campaigns' });
  }

  if (funnelStages(report) !== null) {
    slides.push({ key: 'funnel', kind: 'highlight', part: 'funnel' });
  }

  [...of('store'), ...of('lead')].forEach((section, index) =>
    slides.push({ key: `own-${String(index)}`, kind: 'section', section }),
  );

  /*
   * Creatives are pictures, so the ones worth a screen each get one: the top and the ones to refresh, a creative per
   * screen with its picture large. The other views (a table, a mix, a comparison) are one screen as they are.
   */
  of('creative').forEach((section, sectionIndex) => {
    const rows = section.creative?.rows ?? [];

    if (section.creative?.view === 'top' || section.creative?.view === 'refresh') {
      /* The story's own order: a creative that is tiring comes before one that has already failed. */
      const ordered =
        section.creative.view === 'refresh'
          ? [...rows].sort((a, b) => Number(a.verdict === 'weak') - Number(b.verdict === 'weak'))
          : rows;

      ordered.forEach((row, index) =>
        slides.push({
          key: `creative-${String(sectionIndex)}-${String(index)}`,
          kind: 'creative',
          section,
          row,
          n: index + 1,
          of: rows.length,
        }),
      );

      return;
    }

    slides.push({ key: `creative-${String(sectionIndex)}`, kind: 'section', section });
  });

  if (insights(report).length > 0) {
    slides.push({ key: 'insights', kind: 'highlight', part: 'insights' });
  }

  if (recommendations(report).length > 0) {
    slides.push({ key: 'advice', kind: 'highlight', part: 'advice' });
  }

  slides.push({ key: 'status', kind: 'status' });

  return slides;
}

/** Which way a key moves through the deck. In a right-to-left language the arrows are mirrored, as a book's are. */
export function stepFor(key: string, rtl: boolean): -1 | 0 | 1 {
  const forward = rtl ? 'ArrowLeft' : 'ArrowRight';
  const back = rtl ? 'ArrowRight' : 'ArrowLeft';

  if (key === forward || key === 'PageDown' || key === ' ') {
    return 1;
  }

  if (key === back || key === 'PageUp') {
    return -1;
  }

  return 0;
}

/** A swipe's direction, or 0 when it was a tap, a scroll, or too short to mean anything. */
export function swipeStep(dx: number, dy: number, rtl: boolean): -1 | 0 | 1 {
  if (Math.abs(dx) < 50 || Math.abs(dx) < Math.abs(dy) * 1.5) {
    return 0;
  }

  // A swipe left shows what is to the right in a left-to-right page, and the reverse in a right-to-left one.
  const forward = rtl ? dx > 0 : dx < 0;

  return forward ? 1 : -1;
}
