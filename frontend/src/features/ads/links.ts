/** The Ads screen narrowed to one ad group — what an ad group links to for "its ads". */
export function adsInGroupLink(group: { id: string; name: string }): string {
  return `/ads?group=${encodeURIComponent(`${group.id}~${group.name}`)}`;
}

/** The Ads screen narrowed to one campaign. */
export function adsInCampaignLink(campaign: { id: string; name: string }): string {
  return `/ads?campaign=${encodeURIComponent(`${campaign.id}~${campaign.name}`)}`;
}
