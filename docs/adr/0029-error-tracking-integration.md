# ADR-0029: Error tracking integration (Sentry SDK, compatible with GlitchTip)

- **Status:** Proposed. The provider choice is open question #13 (Sentry vs self-hosted GlitchTip).
- **Date:** 2026-09-23
- **Source:** master plan sections 5, 19.2, 23.3, 24.2 and 29 (#13)

## Context

The plan requires Sentry or GlitchTip with PII and secret scrubbing, `environment` and `release` configured, and tenant grouping as a tag (plan 24.2). The provider decision is pending, but Phase 0 must leave the integration in place.

## Options considered

1. **Sentry SaaS** through `sentry/sentry-laravel`.
2. **GlitchTip, self-hosted.** It implements the Sentry ingestion protocol, so it works with the same SDK and a GlitchTip DSN.
3. **No tracker; logs only.** Loses aggregation and alerting.

## Decision

- Install `sentry/sentry-laravel` (4.28, compatible with Laravel 13) and report unhandled exceptions through `Sentry\Laravel\Integration::handles()`.
- The SDK stays inert unless `SENTRY_LARAVEL_DSN` is set. The DSN decides the provider: a Sentry DSN or a GlitchTip DSN.
- `send_default_pii` is hard-coded to `false` and `before_send` runs `SentryEventScrubber`, which reuses the same `Redactor` as the log channels (request data, extra, contexts, tags, breadcrumbs, message and exception values).
- API client errors (`ApiException` with status < 500) are not reported.

## Rationale

One SDK covers both candidates, so the pending decision does not block Phase 0 and switching later is a configuration change.

## Consequences

- When #13 is decided, set `SENTRY_LARAVEL_DSN`, `SENTRY_ENVIRONMENT` and `SENTRY_RELEASE` in production and update this ADR to Accepted.
- Tenant grouping tags are added in Phase 1, when `TenantContext` exists; tags pass through the redactor too.
