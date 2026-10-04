# 5. Frontend toolchain: ESLint, React Router v8, Tailwind v4

- **Status:** Accepted
- **Date:** 2026-10-04

## Context

The current `create-vite` React template ships **oxlint** rather than ESLint, no strict
TypeScript, and the current React Router major is **v8** while `CLAUDE.md` names v7.

## Decision

- **ESLint** with type-checked `typescript-eslint` rules, as the spec mandates. oxlint
  and its config were removed: two lint toolchains would be a second competing
  standard, which the spec forbids.
- **TypeScript strict**, plus `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`
  and `erasableSyntaxOnly`. `baseUrl` is not used (deprecated in TS 6); path aliases
  resolve relative to the config file.
- **React Router v8** — the current stable major, same data-router API the spec's v7
  refers to. Pinning a superseded major to match the letter of the spec would start the
  project on a deprecated dependency.
- **Tailwind CSS v4** with the Vite plugin and CSS-first `@theme` configuration.

A lint rule forbids cross-feature deep imports, so the `features/` boundary is enforced
rather than merely described.

## Consequences

- `erasableSyntaxOnly` forbids constructor parameter properties; classes such as
  `ApiError` declare fields explicitly.
- Type-checked lint rules need every linted file to be in a tsconfig project, so
  `playwright.config.ts` is included in `tsconfig.node.json`.
- Deviating from the spec's "v7" is recorded here deliberately rather than silently.
