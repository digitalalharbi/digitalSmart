import { describe, expect, it } from 'vitest';
import { funnelSteps } from './funnel';

describe('funnelSteps', () => {
  it('rates each step over the one before and says what is left out', () => {
    const steps = funnelSteps([1000, 100, 25]);

    expect(steps.map((step) => step.rate)).toEqual([null, 10, 25]);
    expect(steps.map((step) => step.dropOff)).toEqual([null, 90, 75]);
  });

  it('has no rate where a count is missing, the earlier one is zero, or a step exceeds the one before it', () => {
    expect(funnelSteps([1000, null, 5]).map((step) => step.rate)).toEqual([null, null, null]);
    expect(funnelSteps([0, 0, 0]).map((step) => step.rate)).toEqual([null, null, null]);
    // A purchase after a view, not a click: a rate over a hundred would be a different population.
    expect(funnelSteps([100, 40, 60]).map((step) => step.rate)).toEqual([null, 40, null]);
  });

  it('marks the lowest-rate step from the point asked, never the steepest-by-nature first one', () => {
    // impressions → clicks (2%), clicks → add to cart (30%), add to cart → purchase (10%).
    const steps = funnelSteps([10_000, 200, 60, 6], 2);

    expect(steps.map((step) => step.isLeak)).toEqual([false, false, false, true]);
  });

  it('marks nothing when fewer than two steps compete', () => {
    expect(funnelSteps([1000, 100, 25], 3).some((step) => step.isLeak)).toBe(false);
    expect(funnelSteps([1000, 100], 1).some((step) => step.isLeak)).toBe(false);
  });

  it('marks nothing unless asked to', () => {
    expect(funnelSteps([1000, 100, 25]).some((step) => step.isLeak)).toBe(false);
  });

  it('breaks a tie towards the step nearer the top', () => {
    const steps = funnelSteps([1000, 500, 250], 1);

    expect(steps.map((step) => step.isLeak)).toEqual([false, true, false]);
  });

  it('does not let a step with no rate win', () => {
    // 3 is larger than 2, so it has no rate and cannot be the leak; the real competition is the other two.
    const steps = funnelSteps([100, 20, 3, 5, 1], 2);

    expect(steps.filter((step) => step.isLeak)).toHaveLength(1);
    expect(steps[3]?.rate).toBeNull();
  });
});
