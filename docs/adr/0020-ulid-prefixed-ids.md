# ADR-0020: Identifiers: ULID primary keys, prefixed in the API

- **Status:** Accepted
- **Date:** 2026-09 (master plan v1.1)
- **Source:** `docs/plans/master.md`, section 3, ADR-020

## Context

Exposed entities need identifiers that are safe to show and easy to support.

## Options considered

The master plan records this decision without listing alternative options.

## Decision

Exposed entities use ULID (`CHAR(26)`, collation `ascii_bin`) as primary key. The API shows them with a type prefix: `plink_01J...`, `pay_01J...`, `re_01J...`, `evt_01J...`. The prefix is added and validated in the HTTP layer; the database stores only the ULID.

## Rationale

Non-sequential (hard to enumerate), time-sortable and coordination-free. The prefix avoids confusion between ID types in support and logs.

## Consequences

- **Exception:** the link `public_token` is not its ID; it is an independent random secret (plan 11.1).
- Phase 0 implements this in `Shared\Ids` (strict decoding; a wrong prefix is a `404 resource_not_found`) and `Shared\Database\HasUlidPrimaryKey` (canonical uppercase ULIDs).
