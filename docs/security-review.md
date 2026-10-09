# Internal security review

This is the build team's own review of the code, done before the external review the plan calls for. It is not a substitute for it. An independent reviewer should still test the running system, the voice-provider integration and the deployment.

Reviewed against: Laravel 12.69 and MariaDB 10.4. Evidence: the test suite (99 tests, on SQLite and on MariaDB) and the simulation in `simulation/` (79 checks on MariaDB with Redis, including concurrency; 70 on SQLite).

## Fixed during this review

| Finding | Risk | Fix |
| --- | --- | --- |
| Webhook delivery followed redirects | A partner could answer with a redirect to an internal address (SSRF) after passing the URL check | Redirects are not followed |
| Webhook host resolved twice (check, then request) | DNS rebinding could swap in an internal address between the two | The request connects to the address that was checked |
| Idempotency table stored the plain code | A database leak would expose live codes | Response body encrypted at rest; rows pruned after 24 hours; the simulation scans the database file for every code it issued and finds none |
| Per-IP limit on the voice endpoint | All provider traffic shares one source address, so the limit would throttle every caller together, and a flood could block real payments | Limit is per number and applies only after the token is checked; bad tokens are limited per source address |
| No retry on database lock or deadlock | Under load the money path could fail mid-way on a deadlock | Money-path transactions retry up to 3 times |
| Audit chain forked under parallel writes, and writers deadlocked | Two simultaneous events for one tenant could extend the chain from the same entry, so verification would report tampering that never happened; the deadlocks also failed some redemptions and left payments waiting for reconciliation. Found only on MariaDB (4 forks, 30 deadlocks in one run) | Writers now take turns on a dedicated per-tenant head row that no foreign key references, and read the previous hash under that lock. Re-run: no forks, no payment-path deadlocks |
| MySQL JSON columns reorder keys | Audit chain would report false tampering | Audit metadata is stored as text |
| Voice number could become "shared" when its tenant was deleted | Calls to it would route by code prefix instead of failing | Deleting a tenant deletes its numbers |
| Laravel 11 advisories (email rule, signed URLs, debug page) | None used by this app, but 11.x gets no fix | Upgraded to Laravel 12.69; `composer audit` is clean |
| No second factor for dashboard admins | Anyone who could sign in could issue API keys and change tenant settings | Standard authenticator-app codes (RFC 6238, checked against its test vectors), with replay protection, a 5-attempt lockout and a 5-minute window between password and code. Required in production. It cannot be switched off from the dashboard; an operator runs `admin:reset-2fa`, which also ends that admin's sessions |
| Failed API-key attempts were unthrottled | A flood of bad keys cost a database lookup each | Per-address limit (30 a minute) on invalid keys |
| Nothing stopped a new tenant-owned table from skipping tenant scoping | A forgotten scope would leak data across tenants | A test fails the build when a table with a `tenant_id` has no scoped model; checked by deliberately removing the scope from one model |
| Revoke-key used an inline script prompt | Blocked by the dashboard's content security policy | A confirmation checkbox the server checks |
| No security headers, open CORS, no proxy trust setting | Clickjacking, cross-origin browser calls, wrong client IPs and https links behind a load balancer | Headers and CSP added, CORS disabled, `TRUSTED_PROXIES` setting |

## Properties checked by the simulation

- A wrong code, an unknown number, a bad token and a caller-ID mismatch all end the same way for the caller, with no hint of which check failed.
- After five wrong guesses a caller is ignored even when they then dial the right code; the real payer on another number is unaffected.
- A code works once; a replay creates no second transaction; a cancelled or expired code cannot be redeemed, even before the expiry sweep has run.
- One tenant cannot read or cancel another's codes, and a tenant sharing the voice number never receives another's events.
- Every webhook (131 in one run) carries a valid signature; a retry carries the same event id.
- Tampering with an audit entry is detected, and the chain verifies again when restored.
- On MariaDB: twelve callers dialling one code at once produce exactly one payment (8 rounds); ten simultaneous retries with one idempotency key create one code; cancel racing a redeem always ends in one clean state (12 rounds); 30 parallel issues across three tenants leave every audit chain intact and unforked; 96 payments with 8 in flight all settle.

## Open items

| # | Item | Why it matters | Suggested action |
| --- | --- | --- | --- |
| 2 | The voice callback token travels in the URL | Africa's Talking does not sign callbacks, so the token appears in provider and proxy logs | Scrub query strings from logs; rotate tokens (dashboard action exists); ask the provider about IP allow-listing |
| 3 | Caller-ID can be spoofed | With caller binding off, only the code protects a payment; with it on, a spoofer who also has the code can pass | Keep codes short-lived; recommend binding to tenants; add per-tenant spend caps (a max amount exists) |
| 4 | Code space is 10^12 | Guessing is limited by per-caller and per-tenant limits, not by the space alone. A spread-out attacker is bounded by the per-tenant limit (120 per minute by default) | Alert when rejected redemptions spike; tune the limit per tenant |
| 5 | Tenant isolation is enforced in application code | A query that bypasses the model scope (raw SQL, `withoutGlobalScopes`) would leak across tenants; the coverage test only catches a missing scope on a model | Review any use of raw queries and `withoutGlobalScopes` (today only the sweeps and the dashboard); use dedicated deployments for banks |
| 6 | Rate limits and the code pepper depend on the cache and `PLATFORM_CODE_PEPPER` | A cache that is not shared between instances weakens limits; changing the pepper invalidates every open code | Use a shared cache (Redis or database); set the pepper once, store it in the secret manager, never rotate it while codes are open |
| 8 | `audit_logs` is immutable only in code | Anyone with database write access can edit it (the chain would reveal this, but not prevent it) | Revoke UPDATE and DELETE on the table for the application's database user |
| 9 | Settlement adapter is trusted | A real adapter will call a bank's system with credentials | Review each adapter: credentials in the secret manager, TLS or mTLS, timeouts, no logging of account data |
| 10 | Concurrency was tested on a development MariaDB, not on the production engine and hardware | The simulation passes 12 simultaneous dials on one code, 10 simultaneous retries with one idempotency key, cancel racing redeem, and 96 payments with 8 in flight, on MariaDB 10.4. Latency there is dominated by a 76 ms commit on a slow disk, so no capacity figure follows from it | Repeat on the production engine and storage; measure under realistic load before agreeing limits with tenants |
| 13 | The database queue driver deadlocks between workers | Laravel's own `jobs` reservation deadlocked 19 times in one run. Workers recover, no payment code is involved, but deliveries are delayed | Use Redis for the queue, cache and rate limits. Verified: the same run on MariaDB with Redis had 0 deadlocks and about half the latency |
| 11 | Logging | Provider requests carry the dialed digits; any request logging, APM or error tracker added later could capture codes | Exclude `dtmfDigits` and the voice route's query string from every logger |
| 12 | Backups and retention | Idempotency rows, webhook deliveries (30 days) and audit entries accumulate | Agree retention periods with each tenant; encrypt backups |

## What an external review should cover

The running deployment (TLS settings, headers, exposed ports), authentication and session handling of the dashboard, the voice-provider integration end to end including a real call, the first real settlement adapter, the key and secret handling in the chosen cloud or HSM, and a concurrency test on the production database engine.
