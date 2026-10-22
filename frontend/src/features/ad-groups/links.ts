/** The Ad Groups screen narrowed to one campaign — what a campaign links to for "its ad groups". */
export function adGroupsLinkFor(campaign: { id: string; name: string }): string {
  return `/ad-groups?campaign=${encodeURIComponent(`${campaign.id}~${campaign.name}`)}`;
}
