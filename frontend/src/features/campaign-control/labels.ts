import type { TFunction } from 'i18next';
import type { CampaignRow } from './types';

/*
 * The product's own words for a campaign's status, objective and result, in the reader's language.
 *
 * The server sends English labels beside every code; those stay as the fallback for a code this build has never
 * heard of, so a new platform status shows something readable rather than a missing-key string.
 */
export const statusName = (
  t: TFunction,
  row: Pick<CampaignRow, 'status' | 'status_label'>,
): string => t(`campaignControl.statusName.${row.status}`, { defaultValue: row.status_label });

export const objectiveName = (
  t: TFunction,
  row: Pick<CampaignRow, 'objective' | 'objective_label'>,
): string =>
  t(`campaignControl.objectiveName.${row.objective}`, { defaultValue: row.objective_label });

export const resultName = (t: TFunction, row: Pick<CampaignRow, 'result_label'>): string =>
  t(`campaignControl.resultName.${row.result_label}`, {
    defaultValue: row.result_label.replace('_', ' '),
  });
