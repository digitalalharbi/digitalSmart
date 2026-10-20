import axios from 'axios';
import { env } from '@/shared/config/env';
import { toSearch, type SharedFilterInfo, type SharedFilterState } from './sharedFilters';
import type { RenderedReport } from './types';

/** What the one unauthenticated read in this product answers with. */
export type SharedReportResult = {
  /** `active` carries a report; the other two carry a reason instead. */
  state: 'active' | 'revoked' | 'expired';
  /** The server's own sentence. Used only if the state is one this build does not know. */
  message: string | null;
  mode: 'live' | 'snapshot' | null;
  recipient_label: string | null;
  report: RenderedReport | null;
  /** What this link can be narrowed by, and what it is narrowed by now. Null when the link is dead. */
  filters?: SharedFilterInfo | null;
};

/**
 * Read a report through a shared link.
 *
 * Its own axios call rather than the shared `api` instance, for three reasons that are all about what a link is.
 *
 * **No credentials.** The token is the authorisation. Sending a session cookie alongside it would mean the one
 * request in this product that needs no account carries the account of whoever happens to be signed in — and on a
 * link an agency opens to check it, the view would be recorded against their own session's cookie rather than
 * against a bare visit.
 *
 * **No unauthenticated handler.** The shared instance routes to the sign-in screen on a 401. A visitor reading a
 * report has nowhere to sign in to, and this endpoint never answers 401 anyway: a dead link is a 200 with a reason.
 *
 * **No locale header.** The server returns no prose worth translating on this path, and the page renders the state
 * in the reader's own language rather than echoing the server's English.
 */
export async function fetchSharedReport(
  token: string,
  filters: SharedFilterState,
  /**
   * True once the page has been opened. A refinement — a platform, a campaign, a date range — is the same visit, and
   * the server does not count it as another view.
   */
  refine: boolean,
): Promise<SharedReportResult> {
  const params = toSearch(filters);

  if (refine) {
    params.set('refine', '1');
  }

  const query = params.toString();
  const { data } = await axios.get<{ data: SharedReportResult }>(
    `${env.apiBaseUrl}/shared-reports/${token}${query === '' ? '' : `?${query}`}`,
    { withCredentials: false, headers: { Accept: 'application/json' } },
  );

  return data.data;
}

/** Whether the server refused a selection because it is not part of this report. */
export function isOutOfScope(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 422;
}
