import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { renderWithProviders } from '@/test/render';
import { ExportsPanel } from './ExportsPanel';
import type { ExportJob } from './types';

const state = vi.hoisted(() => ({
  jobs: [] as unknown[],
  requested: null as Record<string, string> | null,
}));

vi.mock('./api', () => ({
  fetchExports: () => Promise.resolve(state.jobs),
  requestExport: (kind: string, subjectId: string) => {
    state.requested = { kind, subjectId };

    return Promise.resolve({ id: 'export-new' });
  },
  exportDownloadUrl: (id: string) => `/api/exports/${id}/download`,
}));

function job(overrides: Partial<ExportJob>): ExportJob {
  return {
    id: 'export-1',
    kind: 'report_csv',
    subject_id: 'report-1',
    status: 'ready',
    filename: 'Monthly performance 2026-10-01.csv',
    byte_size: 1200,
    error: null,
    requested_at: '2026-10-15T08:00:00Z',
    finished_at: '2026-10-15T08:00:05Z',
    expires_at: '2026-10-22T08:00:05Z',
    ...overrides,
  };
}

function renderPanel() {
  return renderWithProviders(<ExportsPanel reportId="report-1" />);
}

describe('ExportsPanel', () => {
  it('asks for a file by kind and for the open report', async () => {
    state.jobs = [];
    await renderPanel();

    await userEvent.click(screen.getByRole('button', { name: /prepare excel/i }));

    await waitFor(() => {
      expect(state.requested).toEqual({ kind: 'report_xlsx', subjectId: 'report-1' });
    });
  });

  it('offers a download once the file is ready', async () => {
    state.jobs = [job({})];
    await renderPanel();

    const link = await screen.findByRole('link', { name: /download/i });

    expect(link).toHaveAttribute('href', '/api/exports/export-1/download');
  });

  it('says so when one did not finish, and lists only this report', async () => {
    state.jobs = [
      job({
        id: 'export-2',
        status: 'failed',
        filename: null,
        error: 'The file could not be produced.',
      }),
      job({ id: 'export-3', subject_id: 'report-other', filename: 'Other report.csv' }),
    ];
    await renderPanel();

    expect(await screen.findByText('The file could not be produced.')).toBeInTheDocument();
    expect(screen.queryByText('Other report.csv')).not.toBeInTheDocument();
  });

  it('shows a file still being made as in progress, not as a link', async () => {
    state.jobs = [job({ status: 'running', filename: null })];
    await renderPanel();

    expect(await screen.findByText('Preparing…')).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: /download/i })).not.toBeInTheDocument();
  });
});
