export type SearchHitType = 'client' | 'project' | 'campaign' | 'account' | 'lead' | 'report';

export type SearchHit = {
  type: SearchHitType;
  id: string;
  label: string;
  /** What tells two of the same name apart: a project's client, a campaign's account. */
  context: string | null;
  path: string;
};
