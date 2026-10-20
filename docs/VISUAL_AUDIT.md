# Visual audit — measured, not described

**How it was measured (2026-10-20).** The same demo workspace, signed in as the owner, desktop viewport, English, light.
*Before* is the frontend at `372711c` (before the visual restructure) run beside *after* (`main` plus the analytics
branch) against the same API. Each route is opened inside the running application and read after six seconds.

- **Words** — `innerText` of `<main>`, split on whitespace. Hidden hints (ⓘ bubbles, closed `<details>`) are not counted,
  because they are not on the screen.
- **Long** — visible leaf elements of more than 90 characters (a paragraph or a sentence-length note).
- **Tables** — `<table>` elements in the DOM; one closed inside *Table (n)* still counts.
- **Visuals** — `svg` and `canvas` elements (rings, bars, icons and charts alike; a rough proxy, not a chart count).

| Route | Words before → after | Long | Tables | Visuals |
|---|---|---|---|---|
| Today | 1530 → **345** | 0 → 0 | 0 → 0 | 165 → 41 |
| Overview | 529 → 529 | 5 → 5 | 0 → 0 | 30 → 30 |
| Clients | 556 → 554 | 0 → 0 | 0 → 0 | 77 → 78 |
| Projects | 359 → 531 | 0 → 0 | 1 → **0** | 29 → **87** |
| Campaigns | 1401 → 1132 | 1 → 1 | 0 → 0 | 166 → 191 |
| Analytics | 830 → 830 | 5 → **0** | 1 → 1 | 53 → 55 |
| Leads | 2349 → **623** | 10 → **3** | 0 → 0 | 23 → 33 |
| Commerce | 994 → 775 | 6 → **1** | 4 → 4 | 12 → 24 |
| Creative | 1322 → **385** | 2 → 0 | 0 → 0 | 70 → 22 |
| Approvals | 990 → 861 | 5 → **0** | 0 → 0 | 12 → 18 |
| Reports | 720 → 682 | 5 → **0** | 0 → 0 | 2 → **15** |
| Integrations | 1852 → **862** | 4 → 1 | 1 → 1 | 49 → 53 |
| Finance (budget control) | 281 → 295 | 0 → 0 | 0 → 0 | 3 → **7** |
| Agents & Automation | 296 → 284 | 0 → 0 | 1 → **0** | 26 → 34 |
| Tracking | 2775 → **657** | 11 → **2** | 0 → 0 | 10 → **33** |
| Administration | 335 → 301 | 2 → 1 | 1 → 1 | 4 → 7 |
| Presentations | 90 → 68 | 1 → 0 | 0 → 0 | 2 → 8 |

## How to read it

- **Words fell where the screen was a list of sentences** (Today, Leads, Tracking, Creative, Integrations) and **rose
  where a table became cards** (Projects): a card carries a name, a ring and three figures, which is more words than one
  table row and far fewer than the prose it replaced. Words are not the goal; a screen that answers its question in
  seconds is.
- **Unchanged is also a finding.** Overview, Clients and Finance were already card- and ring-led; the numbers say so.
- **Still heavy, in order:** Leads (623, much of it the pipeline cards), Campaigns (1,132 — one card per campaign), Today
  (a long queue, folded to twelve), Approvals (861, two queues). These are lists of work, not prose, and the next pass
  looks at density rather than wording.
- **Tables left on purpose:** Analytics breakdown, Commerce breakdowns (closed behind *Table (n)*), the member directory,
  the audit trail, the integrations matrix.

## Not measured, so not claimed

Mobile and Arabic were **inspected** for the screens changed in batch 4 (Leads in Arabic dark; Commerce on a phone in
Arabic dark, no horizontal overflow) but **not measured** for every route. Mobile Safari is covered by the end-to-end
suite in CI, not by this table. Demo data has no real creative assets, so Creative thumbnails are placeholders.
