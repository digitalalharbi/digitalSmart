# Integrations

## Status: `BLOCKED_EXTERNAL`

No ad-platform integration can perform a live handshake yet. Every provider below needs
credentials or an approval that only the account owner can obtain. **This blocks the
live handshake only** — adapter contracts, the credential model, UI, sandbox fixtures
and tests are built regardless, and the rest of the roadmap continues unaffected.

Nothing in this repository fabricates a successful connection or invents metrics. An
unconfigured provider reports exactly that.

## What is needed, per provider

| Provider   | Required to unblock                                                                 | Env keys |
| ---------- | ----------------------------------------------------------------------------------- | -------- |
| Meta Ads   | Meta app (Business type), Marketing API access, `ads_read`/`ads_management` review, redirect URI allow-listed | `META_ADS_CLIENT_ID`, `META_ADS_CLIENT_SECRET` |
| Google Ads | Google Cloud OAuth client, **developer token** approved for production by Google     | `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET`, `GOOGLE_ADS_DEVELOPER_TOKEN` |
| TikTok Ads | TikTok for Business developer app, Marketing API approval                            | `TIKTOK_ADS_APP_ID`, `TIKTOK_ADS_APP_SECRET` |
| Snapchat   | Snap Business app with Marketing API access                                          | `SNAPCHAT_ADS_CLIENT_ID`, `SNAPCHAT_ADS_CLIENT_SECRET` |
| X Ads      | X developer account with **Ads API access** (separately approved, often paid)         | `X_ADS_CONSUMER_KEY`, `X_ADS_CONSUMER_SECRET` |
| LinkedIn   | LinkedIn app with Advertising API access (partner programme approval)                 | `LINKEDIN_ADS_CLIENT_ID`, `LINKEDIN_ADS_CLIENT_SECRET` |
| ChatGPT Ads| Programme availability and API access — **not generally available**; contract and capability set unknown until access is granted | `OPENAI_ADS_API_KEY` |

These are business/account actions, not engineering work: they need the owner's
identity, legal acceptance and in some cases payment. That is why they are the one
class of thing the build stops for.

## Architecture

Provider logic sits behind stable ports. No provider-specific code ever appears in a
controller or a domain model.

```
Application port ─── ProviderAdapter (interface)
                         ├─ MetaAdsAdapter
                         ├─ GoogleAdsAdapter
                         ├─ TikTokAdsAdapter
                         ├─ SnapchatAdsAdapter
                         ├─ XAdsAdapter
                         ├─ LinkedInAdsAdapter
                         └─ OpenAIAdsAdapter
```

Each adapter exposes: auth/connect, account discovery, campaign discovery, metrics sync,
creative requirements, campaign mutation *where supported*, error normalisation,
rate-limit handling and a health check.

### Isolation model (mandatory)

```
ProviderConnection ──▶ ExternalAccount ──▶ ProjectIntegrationBinding ──▶ Project
```

A project syncs **only** the external accounts explicitly bound to it. There is no
implicit inheritance from workspace or client level.

### Capability flags

Adapters declare what they can actually do. The UI reads those flags: a provider that
cannot mutate budgets does not render a budget-edit control. We never implement a
write path a provider does not support, and never simulate one.

## Credential handling

- Stored encrypted (AES-256-GCM via `APP_KEY`) on `ProviderConnection`.
- Never returned by an API resource, never logged, never placed in a URL.
- Refresh tokens are rotated by a queued job before expiry; failures raise an
  actionable connection-health alert rather than silently degrading.
- **Client platform passwords are never requested or stored** — see `docs/SECURITY.md`.

## Client onboarding

Rather than asking for passwords, each provider gets a guided access flow:
OAuth where possible, otherwise Business Manager / Business Center asset sharing,
partner access, account IDs, pixels/datasets, pages and conversion assets — with a
per-provider checklist showing what is still missing.

## Sandbox behaviour

Until credentials exist, adapters run against recorded fixtures. Sandbox responses are
labelled `DEMO`/`SANDBOX` end to end, in the API payload and in the UI, so a sandbox
number can never be mistaken for client money.
