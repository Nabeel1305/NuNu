# Offline Payment Platform

Multi-tenant API for voice-call (DTMF) payments. A tenant's app asks the API for a one-time code, the payer dials it later, and the platform tells the tenant's own system to settle. The platform never holds funds. Design: see the design document.

## Run

    composer install
    cp .env.example .env && php artisan key:generate
    # create the database named in DB_DATABASE, then:
    php artisan migrate
    php artisan admin:create "Your Name" you@example.com      # dashboard login, at /admin
    php artisan tenant:create "Acme Bank"                      # or use the dashboard; prints the API key once
    php artisan voice-number:add +2347000000001 --tenant=<slug> # prints the callback URL once
    php artisan queue:work                                     # webhook delivery
    php artisan schedule:work                                  # expires codes, reconciles lost captures, prunes old rows

Run a real queue in every environment except tests: with `QUEUE_CONNECTION=sync` webhooks are delivered inside the request. Prefer Redis for the queue, cache and rate limits in production: the database queue driver deadlocks between workers (see `docs/security-review.md`).

After pulling changes, run `php artisan migrate`. A tenant created before the audit chain head table existed is backfilled by the migration.

## API (all under `/api/v1`, `Authorization: Bearer <key>`)

| Method | Path | Notes |
| --- | --- | --- |
| PUT | `/subscribers/{reference}` | `phone` optional |
| PUT | `/merchants/{reference}` | `name`, `account_reference` |
| POST | `/webhook-endpoints` | `url` (https), optional `events`; returns the signing `secret` once |
| POST | `/codes` | needs `Idempotency-Key`; returns the code once |
| GET | `/codes/{id}` | never includes the code |
| POST | `/codes/{id}/cancel` | needs `Idempotency-Key` |
| GET | `/transactions/{id}` | |

Voice callback: `POST /api/voice/africastalking?token=...` (Africa's Talking). Webhooks are signed: `Offline-Signature: t=<unix>,v1=<hmac_sha256("<t>.<body>")>`.

## Settlement

`App\Services\Settlement\SettlementAdapter` (hold, capture, release, status) is the contract with a tenant's core system. Only `SandboxAdapter` exists; it is refused for live tenants. A real adapter is added when a tenant's API is available.

## Tests

    php vendor/bin/phpunit

Unit tests need nothing. Feature tests default to in-memory SQLite (`phpunit.xml`) and also run on MySQL/MariaDB:

    DB_CONNECTION=mysql DB_DATABASE=offline_platform_test php vendor/bin/phpunit

The suite wipes its database every run, so `tests/TestCase.php` refuses any non-SQLite database whose name does not end in `_test`.

## Dashboard

`/admin` is for platform operators (not tenants), protected by password plus an authenticator-app code (required in production; `PLATFORM_ADMIN_REQUIRE_2FA=false` to relax it elsewhere). An admin sets theirs up at `/admin/security`; if they lose the phone, `php artisan admin:reset-2fa <email>` (server access) resets it and ends their sessions. Here you can create tenants, change settings, issue and revoke API keys, add and rotate voice numbers, see webhook deliveries and the audit log, and verify the audit chain. There is no tenant self-service yet.

## Operations

| Command | What it does |
| --- | --- |
| `codes:expire` | Expires overdue codes and releases their holds (scheduled every minute) |
| `transactions:reconcile` | Asks the tenant what happened to captures that never reported back (every five minutes); flags any still unanswered after 24 hours |
| `audit:verify {tenant?}` | Checks audit chains; exits 1 on tampering |
| `model:prune` | Removes idempotency keys older than a day and finished webhook deliveries older than 30 days (daily) |

## Seed data and simulation

    php artisan db:seed        # demo tenants, numbers, payers, merchants; refuses to run in production
    ./simulation/run.sh        # SQLite, one web worker; set PHP_BIN if PHP needs -d flags
    SIM_REDIS=1 SIM_DB=mysql ./simulation/run.sh   # add Redis for queue, cache and rate limits (SIM_REDIS_HOST/PORT; per-run key prefix, never flushes)
    SIM_DB=mysql ./simulation/run.sh   # MySQL/MariaDB from .env, 8 web + 2 queue workers, adds the concurrency scenarios
                                       # (drops and recreates SIM_MYSQL_DATABASE, default offline_platform_sim; name must end in _sim)

The simulation boots the real server, a queue worker and a fake partner webhook server on a throwaway SQLite database, then plays partners and callers through 17 scenarios (including two-factor enrolment and sign-in) (happy path, replays, hostile guessing, shared-number isolation, expiry, lost capture results, flaky webhook servers, audit tampering, the dashboard, 60 payments in a row) and checks invariants over the whole run. It exits 1 on any failure.

## Docs and clients

- `docs/integration-guide.md` and `docs/openapi.yaml` for partners.
- `clients/php` and `clients/js`: small API clients with webhook signature verification.
- `docs/security-review.md`: internal review and open items.
