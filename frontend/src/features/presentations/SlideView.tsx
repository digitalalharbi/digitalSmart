import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { EChartsCoreOption } from 'echarts/core';
import { Chart } from '@/shared/ui/chart';
import type { DeckSlide, DeckTheme } from './types';

/**
 * One slide, drawn at the theme's own aspect ratio.
 *
 * **This is also the PDF.** There is no server-side PDF writer: a reader prints this page, where the Arabic
 * shaping is the browser's own, the fonts are the product's self-hosted faces, and the charts are the same
 * engine the rest of the application uses. A converter on the server would be a second rendering engine with
 * weaker Arabic support, and it would eventually disagree with what somebody previewed.
 *
 * Everything is sized from the theme in points, scaled to the width the slide is rendered at. A stylesheet with
 * its own spacing would drift from the exported file the first time either changed.
 *
 * **Nothing here formats a number.** Every figure arrived as a string the server produced, which is what makes
 * the page and the PowerPoint file agree.
 */
export function SlideView({
  slide,
  theme,
  index,
  total,
}: {
  slide: DeckSlide;
  theme: DeckTheme;
  index: number;
  total: number;
}) {
  const { t, i18n } = useTranslation();
  const isRtl = i18n.dir() === 'rtl';

  /** Points to a proportion of the slide's own width, so one stylesheet serves any render size. */
  const scale = (points: number): string => `${(points / theme.slide_width_pt) * 100}cqw`;

  const option = useMemo<EChartsCoreOption>(() => {
    const isBar = slide.kind === 'breakdown';

    return {
      animation: false,
      grid: { top: 16, bottom: 8, left: 8, right: 16, containLabel: true },
      legend:
        slide.series.length > 1
          ? { top: 0, textStyle: { color: `#${theme.ink_muted}`, fontSize: 11 } }
          : { show: false },
      tooltip: { trigger: isBar ? 'item' : 'axis' },
      xAxis: isBar
        ? { type: 'value', inverse: isRtl, show: false }
        : {
            type: 'category',
            inverse: isRtl,
            data: slide.categories,
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { color: `#${theme.ink_muted}`, fontSize: 10 },
          },
      yAxis: isBar
        ? {
            type: 'category',
            position: isRtl ? 'right' : 'left',
            data: [...slide.categories].reverse(),
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { color: `#${theme.ink}`, fontSize: 11 },
          }
        : {
            type: 'value',
            position: isRtl ? 'right' : 'left',
            splitLine: { lineStyle: { color: `#${theme.line}` } },
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { color: `#${theme.ink_muted}`, fontSize: 10 },
          },
      series: slide.series.map((series, position) => ({
        name: series.name,
        type: isBar ? 'bar' : 'line',
        /* Reversed for a horizontal bar chart so the largest sits at the top, as the server ordered it. */
        data: isBar ? [...series.points].reverse() : series.points,
        /* Null stays a gap. A zero drawn in its place is a line through the floor. */
        connectNulls: false,
        smooth: false,
        symbol: 'circle',
        symbolSize: 6,
        lineStyle: { width: 2 },
        itemStyle: { color: `#${theme.series[position % theme.series.length] ?? theme.accent}` },
        barMaxWidth: 18,
        itemRadius: 4,
      })),
    };
  }, [slide, theme, isRtl]);

  const isTitle = slide.kind === 'title';

  return (
    <article
      /*
       * A container query unit, so every measurement below is a proportion of this slide rather than of the
       * viewport — which is what lets the same markup be a thumbnail, a full-width preview and a printed page.
       */
      className="ds-slide relative w-full overflow-hidden rounded-lg border shadow-sm"
      style={{
        containerType: 'inline-size',
        aspectRatio: `${theme.slide_width_pt} / ${theme.slide_height_pt}`,
        backgroundColor: `#${theme.canvas}`,
        borderColor: `#${theme.line}`,
        color: `#${theme.ink}`,
        fontFamily: isRtl ? theme.arabic_font : theme.latin_font,
      }}
      aria-label={t('presentations.slide.number', { index: index + 1, total })}
    >
      <div
        className="flex h-full flex-col"
        style={{ padding: `${scale(48)} ${scale(48)}`, gap: scale(16) }}
      >
        <header className="flex flex-col" style={{ gap: scale(8) }}>
          <h2
            className="font-semibold"
            style={{ fontSize: scale(isTitle ? theme.title_size : theme.heading_size) }}
          >
            {slide.title}
          </h2>
          {slide.lead === null ? null : (
            <p style={{ fontSize: scale(theme.body_size), color: `#${theme.ink_muted}` }}>
              {slide.lead}
            </p>
          )}
        </header>

        <div className="min-h-0 flex-1">
          {slide.kind === 'scorecard' && slide.figures.length > 0 ? (
            <ul
              className="grid h-full content-start"
              style={{
                gap: scale(16),
                gridTemplateColumns: `repeat(${Math.min(3, slide.figures.length)}, minmax(0, 1fr))`,
              }}
            >
              {slide.figures.map((figure) => (
                <li key={figure.label} className="flex flex-col" style={{ gap: scale(4) }}>
                  <span
                    style={{ fontSize: scale(theme.caption_size), color: `#${theme.ink_muted}` }}
                  >
                    {figure.label}
                  </span>
                  <span
                    className="font-semibold tabular-nums"
                    style={{ fontSize: scale(theme.heading_size) }}
                  >
                    {figure.value}
                  </span>
                  <span
                    style={{ fontSize: scale(theme.caption_size), color: `#${theme.ink_muted}` }}
                  >
                    {/*
                      The change when there is one, the reason when the figure is absent, and the source state
                      always — a figure with no source on a slide is a figure that gets quoted without one.
                    */}
                    {figure.unavailable_reason ??
                      (figure.change === null
                        ? figure.source_label
                        : `${figure.change} · ${figure.source_label}`)}
                  </span>
                </li>
              ))}
            </ul>
          ) : slide.kind === 'trend' || slide.kind === 'breakdown' ? (
            <Chart option={option} height="100%" ariaLabel={slide.title}>
              <ul>
                {slide.categories.map((category, position) => (
                  <li key={category}>
                    {category}: {slide.series.map((series) => series.labels[position]).join(', ')}
                  </li>
                ))}
              </ul>
            </Chart>
          ) : slide.table !== null ? (
            <table
              className="w-full border-collapse"
              style={{ fontSize: scale(theme.caption_size) }}
            >
              <caption className="sr-only">{slide.title}</caption>
              <thead>
                <tr
                  style={{
                    backgroundColor: `#${theme.accent}`,
                    color: `#${theme.accent_contrast}`,
                  }}
                >
                  {slide.table.columns.map((column) => (
                    <th
                      key={column}
                      scope="col"
                      className="text-start font-medium"
                      style={{ padding: `${scale(6)} ${scale(8)}` }}
                    >
                      {column}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {slide.table.rows.map((row) => (
                  <tr key={row.label} style={{ borderBottom: `1px solid #${theme.line}` }}>
                    <th
                      scope="row"
                      className="text-start font-normal"
                      style={{ padding: `${scale(6)} ${scale(8)}` }}
                    >
                      {row.label}
                    </th>
                    {row.cells.map((cell, position) => (
                      <td
                        key={position}
                        className="tabular-nums"
                        style={{ padding: `${scale(6)} ${scale(8)}` }}
                      >
                        {cell}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          ) : slide.paragraphs.length > 0 ? (
            <ul
              className="flex flex-col"
              style={{ gap: scale(10), fontSize: scale(theme.body_size) }}
            >
              {slide.paragraphs.map((paragraph, position) => (
                <li key={position}>{paragraph}</li>
              ))}
            </ul>
          ) : null}
        </div>

        {slide.notes.length === 0 ? null : (
          <footer
            className="flex flex-col"
            style={{
              gap: scale(2),
              fontSize: scale(theme.caption_size),
              color: `#${theme.ink_muted}`,
            }}
          >
            {slide.notes.map((note, position) => (
              <span key={position}>{note}</span>
            ))}
          </footer>
        )}
      </div>
    </article>
  );
}
