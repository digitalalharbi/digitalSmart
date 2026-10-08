/**
 * The backup channel: patterns, for when colour is not available.
 *
 * The palette clears every colour-blindness gate it is measured against — see
 * `palette.gates.test.ts` — and that is still not enough in three situations colour cannot reach:
 *
 *  1. **Full colour blindness.** Achromatopsia is rare and real, and no pair of hues separates for
 *     somebody who sees none of them.
 *  2. **Grey printing.** A client prints a report on an office laser and eight hues become eight
 *     greys, several of them the same grey.
 *  3. **Forced colours.** Windows high-contrast mode replaces the page's colours with the user's
 *     own, and a canvas it cannot repaint keeps its own — so a chart drawn on a repainted page can
 *     end up with marks that no longer separate from their background.
 *
 * **The mechanism is ECharts' own.** `aria.decal` is the documented way to add a pattern per
 * series, so this file configures that rather than painting patterns onto a canvas by hand; the
 * patterns are ours, the drawing is theirs. Checked against the installed library's own types and
 * the Apache ECharts handbook (read 8 October 2026): `aria.decal.show` takes effect only with
 * `aria.enabled`, and `AriaComponent` has to be registered explicitly.
 *
 * **`aria.label` is switched off while doing it**, which is the one non-obvious decision here.
 * Enabling `aria` also makes ECharts generate a sentence describing the chart and put it on the
 * element — a second, machine-written claim about figures this product is careful about, competing
 * with the description the chart was required to supply. Every chart here already has a written
 * description and a table of its own values, so the generated one is turned off rather than
 * allowed to contradict them.
 *
 * **Lines are handled separately.** A decal fills a shape, and a line has no fill, so ECharts
 * leaves line series untouched. They get a dash pattern by slot instead, from the same fixed
 * order, which is why both lists live in `theme.ts` beside the colours.
 *
 * **What this cannot do**, stated rather than implied: a single series carrying a colour per datum
 * — an ordinal bar chart where each bar is a pipeline stage — is one series to ECharts and gets one
 * decal. Those charts separate their bars by axis label, which they all have.
 */
import { useEffect, useState } from 'react';
import type { EChartsCoreOption } from 'echarts/core';
import { useUiStore } from '@/shared/store/ui-store';
import { seriesDashes, seriesDecals } from './theme';

/** The aria block that turns the channel on. Shaped for `setOption`, not for a reader. */
export function textureOption(): Record<string, unknown> {
  return {
    aria: {
      enabled: true,
      /* See the note above: the product writes its own description, and two would disagree. */
      label: { enabled: false },
      decal: { show: true, decals: seriesDecals.map((decal) => ({ ...decal })) },
    },
  };
}

/**
 * Add the backup channel to a chart's option, or take it away.
 *
 * Pure, and exhaustive about `isOn === false`: a reader who turns the preference off has to get a
 * chart with no patterns on it, which means writing the disabling values rather than omitting the
 * keys — `setOption` is called with `notMerge`, but the same option object is also handed to a
 * second chart, and an absent key would leave whatever the instance had.
 */
export function withTexture(option: EChartsCoreOption, isOn: boolean): EChartsCoreOption {
  if (!isOn) {
    return {
      ...option,
      aria: { enabled: false },
      series: dashSeries(option, false),
    };
  }

  return {
    ...option,
    ...textureOption(),
    series: dashSeries(option, true),
  };
}

/**
 * Dash each line series by its slot, because a decal cannot reach a line.
 *
 * The slot is the series' position in the list, which is the same thing the colours are handed out
 * by — so a line's pattern and its colour always name the same series. A series that already
 * carries an explicit `lineStyle.type` keeps it: a threshold or a comparison line is dashed to say
 * *"this is not data"*, and overwriting that would make a target look like a measurement.
 */
function dashSeries(option: EChartsCoreOption, isOn: boolean): unknown {
  const series: unknown = (option as { series?: unknown }).series;

  if (!Array.isArray(series)) {
    return series;
  }

  let lineIndex = 0;

  /*
   * `unknown[]` rather than the library's series union. A chart's option is assembled by the mark
   * helpers and is opaque here on purpose: this pass needs two fields of it and should not acquire
   * an opinion about the rest, because then every new chart form would have to be taught to it.
   */
  const entries: unknown[] = series;

  return entries.map((entry): unknown => {
    if (entry === null || typeof entry !== 'object') {
      return entry;
    }

    const record = entry as Record<string, unknown>;

    if (record['type'] !== 'line') {
      return entry;
    }

    const slot = lineIndex;
    lineIndex += 1;

    const lineStyle =
      record['lineStyle'] === null || typeof record['lineStyle'] !== 'object'
        ? {}
        : (record['lineStyle'] as Record<string, unknown>);

    /* Already saying something by being dashed. Left alone in both directions. */
    if (lineStyle['type'] !== undefined && lineStyle['type'] !== 'solid') {
      return entry;
    }

    return {
      ...record,
      lineStyle: {
        ...lineStyle,
        type: isOn ? [...(seriesDashes[slot % seriesDashes.length] ?? [])] : 'solid',
      },
      /*
       * Markers come on with the patterns. A dash is hard to follow where lines cross, and the
       * symbol at each measured point is the thing that says which line is which there.
       */
      showSymbol: isOn ? true : record['showSymbol'],
    };
  });
}

/**
 * Whether this chart should draw its patterns, right now.
 *
 * Three inputs, and the hook exists because two of them are events rather than values:
 *
 *  - **The reader's own preference**, which no media query can detect. Full colour blindness does
 *    not announce itself to a browser, and neither does a report about to be photocopied.
 *  - **`forced-colors: active`**, which a canvas cannot honour by itself: the page around it is
 *    repainted in the reader's colours and the chart's are left as they were.
 *  - **A print in progress.** A canvas prints whatever was last drawn on it, so the patterns have
 *    to be there *before* the print dialog opens — which is what `beforeprint` is for. Without
 *    this, "it works on screen" and "it works on paper" are different answers, and the paper one is
 *    the one a client keeps.
 */
export function useTextureChannel(): boolean {
  const preference = useUiStore((state) => state.chartTexture);
  const [environmentNeedsIt, setEnvironmentNeedsIt] = useState(false);

  useEffect(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
      return;
    }

    const forced = window.matchMedia('(forced-colors: active)');
    let isPrinting = false;

    const sync = () => setEnvironmentNeedsIt(forced.matches || isPrinting);

    const onBeforePrint = () => {
      isPrinting = true;
      sync();
    };

    const onAfterPrint = () => {
      isPrinting = false;
      sync();
    };

    sync();
    forced.addEventListener('change', sync);
    window.addEventListener('beforeprint', onBeforePrint);
    window.addEventListener('afterprint', onAfterPrint);

    return () => {
      forced.removeEventListener('change', sync);
      window.removeEventListener('beforeprint', onBeforePrint);
      window.removeEventListener('afterprint', onAfterPrint);
    };
  }, []);

  return preference === 'always' || environmentNeedsIt;
}
