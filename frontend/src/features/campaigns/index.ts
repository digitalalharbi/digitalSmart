/*
 * Intentionally empty of page exports.
 *
 * `CampaignsPage` imports the charting engine, so re-exporting it here would let
 * any importer of this barrel pull a 600 kB chunk into the initial bundle. The
 * router imports the page module directly, lazily. If this feature grows parts
 * worth sharing, they belong here; the page does not.
 */
export {};
