import * as echarts from 'echarts/core';
import { BarChart, LineChart, PieChart, ScatterChart } from 'echarts/charts';
import {
  DataZoomComponent,
  GridComponent,
  LegendComponent,
  MarkLineComponent,
  TitleComponent,
  TooltipComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { useEffect, useId, useRef } from 'react';
import type { EChartsCoreOption } from 'echarts/core';
import { cn } from '@/shared/lib/cn';

/*
 * Registered explicitly rather than importing all of ECharts: the full bundle is
 * roughly a megabyte, and a dashboard that loads slowly is a dashboard people
 * stop opening. Adding a chart type means adding it here.
 */
echarts.use([
  LineChart,
  BarChart,
  PieChart,
  ScatterChart,
  GridComponent,
  TooltipComponent,
  LegendComponent,
  TitleComponent,
  DataZoomComponent,
  MarkLineComponent,
  CanvasRenderer,
]);

type ChartProps = {
  option: EChartsCoreOption;
  /** Height in CSS units. Charts need an explicit height; a canvas has no content to size to. */
  height?: number | string;
  className?: string;
  /**
   * An accessible description of what the chart shows and says.
   *
   * Required, not optional. A canvas is invisible to a screen reader, so a chart
   * without one is a hole in the page — and writing it forces the question of
   * what the chart is actually for.
   */
  ariaLabel: string;
  /** Extra detail for assistive technology, rendered inside the caption. */
  children?: React.ReactNode;
  onEvent?: Partial<Record<'click', (params: unknown) => void>>;
};

export function Chart({
  option,
  height = 320,
  className,
  ariaLabel,
  children,
  onEvent,
}: ChartProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const instanceRef = useRef<echarts.ECharts | null>(null);
  const titleId = useId();

  useEffect(() => {
    const container = containerRef.current;

    if (container === null) {
      return;
    }

    const instance = echarts.init(container, undefined, {
      renderer: 'canvas',
      // Only repaint what changed. A dashboard refreshing several charts a minute
      // otherwise redraws every pixel of each one.
      useDirtyRect: true,
    });

    instanceRef.current = instance;

    /*
     * ResizeObserver rather than a window listener: these live inside a grid
     * whose columns change with the sidebar and the viewport, so a chart can need
     * to resize when the window has not.
     */
    const observer = new ResizeObserver(() => instance.resize());
    observer.observe(container);

    return () => {
      observer.disconnect();
      instance.dispose();
      instanceRef.current = null;
    };
  }, []);

  useEffect(() => {
    const instance = instanceRef.current;

    if (instance === null) {
      return;
    }

    /*
     * ECharts animates by default, and a dashboard that animates on every refresh
     * is actively unpleasant for anyone sensitive to motion. The preference is
     * read here rather than in CSS because a canvas is not styled by CSS.
     */
    const prefersReducedMotion =
      typeof window !== 'undefined' &&
      typeof window.matchMedia === 'function' &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // `true` replaces the option rather than merging: a series list that shrank
    // would otherwise keep its old members.
    instance.setOption({ ...option, animation: !prefersReducedMotion }, true);
  }, [option]);

  useEffect(() => {
    const instance = instanceRef.current;
    const handler = onEvent?.click;

    if (instance === null || handler === undefined) {
      return;
    }

    instance.on('click', handler);

    return () => {
      instance.off('click', handler);
    };
  }, [onEvent]);

  return (
    <figure className={cn('relative m-0', className)}>
      <div
        ref={containerRef}
        style={{ height: typeof height === 'number' ? `${height}px` : height }}
        role="img"
        aria-labelledby={titleId}
      />
      {/*
        The canvas carries no text, so the same figures are also rendered for
        assistive technology and for anyone who cannot see colour differences.
        Visually hidden rather than absent.
      */}
      <figcaption id={titleId} className="sr-only">
        {ariaLabel}
        {children}
      </figcaption>
    </figure>
  );
}
