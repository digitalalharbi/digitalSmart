import type { Project } from './types';

/** How far through its dates a project is, 0–100; null when either end is not set. */
export function scheduleProgress(
  project: Pick<Project, 'starts_on' | 'ends_on'>,
  now: number,
): number | null {
  if (project.starts_on === null || project.ends_on === null) return null;

  const start = Date.parse(project.starts_on);
  const end = Date.parse(project.ends_on);

  if (Number.isNaN(start) || Number.isNaN(end) || end <= start) return null;

  return Math.min(100, Math.max(0, ((now - start) / (end - start)) * 100));
}
