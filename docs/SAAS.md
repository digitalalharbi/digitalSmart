# digitalSmart as SaaS

Workspace is the tenant and (from PR C/D) the subscription boundary. Clients and projects are scopes inside it. The program that
gets there is `docs/SAAS_CAMPAIGN_MASTER.md`; tenant isolation is `docs/TENANCY.md`.

## One identity, many workspaces (PR B)

- **One person is one `users` row** with one password. They belong to workspaces through `workspace_members`; a second workspace
  never means a second account.
- **A membership** carries the workspace, the person, a role, a status, `joined_at`, `invited_by` / `invited_at` (who let them
  in, recorded when they are added by a person), and `revoked_at` / `revoked_by` once it ends.
- **Statuses**: `invited` (reserved, grants nothing), `active` (the only one that grants anything), `suspended` (paused,
  expected back, grants nothing), `revoked` (ended). A revoked membership is **kept** as the record of who was here and who
  took them out; inviting the same person again **reactivates the same row**. The member directory hides the revoked unless
  asked (`?status=revoked`).
- **Workspace type**: `agency`, `brand`, `freelancer`, `internal_team`. It may change defaults and wording; it never changes
  authorization or isolation.

## Working in one workspace at a time

- Sign-in lands in the earliest active membership. The browser then works in one workspace until it switches.
- **The active workspace is held in its own encrypted, HTTP-only cookie** (`ds_workspace`), written only by sign-in and by a
  switch, and re-checked against an active membership on **every** request. It used to live only in the session, which the
  session driver writes back whole at the end of every request: a poll that began before a switch and ended after it wrote the
  old workspace back, and the browser reloaded into the tenant it had just left. The cookie cannot be undone by a request in
  flight. A cookie naming a workspace the person does not (or no longer) belong to is ignored.
- **Switching** (`POST /api/workspaces/{id}/activate`) re-checks the membership (another workspace, a suspended or revoked one:
  not found) and is **audited** in the workspace entered (`workspace.switched`, the workspace left named by id only).
- **The workspace switcher** sits in the top bar for anybody with more than one workspace: each workspace with its type and the
  role held there. On a switch the browser **removes** every cached read of the previous workspace (not refreshes it, which
  would show the old tenant until the new answer arrived), forgets the client/project context, and goes to the new
  workspace's home.

## Proof

- `tests/Feature/Identity/MultiWorkspaceIdentityTest.php` — one identity in an agency, a brand and a freelancer workspace: one
  sign-in, a different role, clients and provider connections in each, nothing shared; suspended and stranger workspaces refused;
  the switch audited; revoke-not-delete and reactivation; the in-flight session race; a forged cookie ignored.
- `src/app/layout/WorkspaceSwitcher.test.tsx`, `src/features/auth/switch.test.tsx` — the switcher, and the cache and context
  cleared on a switch.
- `e2e/workspace-switcher.spec.ts` — the demo owner switches between the demo agency and the demo brand, sees only that
  tenant's clients, and the switcher fits a 375px phone (Chromium, Firefox, WebKit, Mobile Safari, Mobile Chromium).

## Plans, features and limits (PR C)

What a workspace has — its plan, the features in it, its limits and seats, and any override — is in `docs/ENTITLEMENTS.md`. It
sits beside permissions and never replaces them.
