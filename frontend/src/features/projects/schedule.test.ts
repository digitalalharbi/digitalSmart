import { describe, expect, it } from 'vitest';
import { scheduleProgress } from './schedule';

const at = (iso: string) => Date.parse(iso);

describe('scheduleProgress', () => {
  const project = { starts_on: '2026-01-01', ends_on: '2026-01-11' };

  it('says how far through its dates a project is', () => {
    expect(scheduleProgress(project, at('2026-01-06'))).toBe(50);
  });

  it('stays inside 0–100, before the start and after the end', () => {
    expect(scheduleProgress(project, at('2025-12-01'))).toBe(0);
    expect(scheduleProgress(project, at('2026-03-01'))).toBe(100);
  });

  it('has no progress to show without both dates, or with an end before the start', () => {
    expect(scheduleProgress({ starts_on: null, ends_on: '2026-01-11' }, 0)).toBeNull();
    expect(scheduleProgress({ starts_on: '2026-01-11', ends_on: '2026-01-01' }, 0)).toBeNull();
  });
});
