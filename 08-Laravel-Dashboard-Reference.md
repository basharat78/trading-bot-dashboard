# Laravel Dashboard: Reference & Integration Design

Reference for building the optional Laravel dashboard (Phase 10 in `07-Development-Plan.md`) as a **separate project** that works cleanly with the Python engine.

Status: design only. Nothing built yet. Build after Phase 6, when real data exists.

## 1. Principles

1. **Two separate apps, one shared contract.** Python and Laravel never import each other. They share a database schema and a small set of rules.
2. **Python owns trading. Laravel owns viewing and control requests.** Laravel can *request* a pause; it can never place orders or override risk limits.
3. **Hard risk limits live in Python.** Dashboard settings are suggestions the engine validates against its own maximums.
4. **No inbound ports on the trading machine.** The engine (on a Windows VPS, because of MT5) polls the database for commands. Nothing on the internet connects to it.
5. **One side owns the schema.** Laravel owns it, via migrations (your strength). Python only reads and inserts against those tables.

## 2. Architecture

```
 Windows VPS                          Managed DB                  Web server
 ┌──────────────────────┐        ┌────────────────┐        ┌─────────────────┐
 │ MT5 + Python engine  │─write─▶│  MySQL /       │◀─read──│ Laravel         │
 │  - detectors, ML,LLM │        │  PostgreSQL    │        │  - dashboard    │
 │  - risk, execution   │◀─poll──│  (TLS, IP      │─write─▶│  - queues/jobs  │
 │                      │commands│   allowlist)   │commands│  - auth         │
 └──────────────────────┘        └────────────────┘        └─────────────────┘
```

Why this shape:
- The engine only makes **outbound** connections, so there is no attack surface on the machine that holds broker access.
- If the dashboard goes down, trading continues. If the engine goes down, the dashboard shows it as offline via the heartbeat.
- The database is the audit trail for both.

Optional later: a read-only FastAPI service on the engine for live data. Not needed for v1.

## 3. Database contract

Laravel migrations create these tables. Python writes to the first group; Laravel writes to the second. Use separate DB users with minimum privileges.

**Rules for every table**
- Money and prices: `DECIMAL(18,5)` or higher. **Never floats.**
- All timestamps stored in **UTC**. Convert to NY or local time only for display.
- Engine-written rows include an `external_id` (unique) so retries do not create duplicates.
- Never hard-delete trading records.

### Written by Python (Laravel: read-only)

| Table | Key columns | Purpose |
|---|---|---|
| `accounts` | id, broker, login, mode (`demo`/`live`) | Which account the engine trades |
| `trades` | external_id, account_id, symbol, side, volume, entry_price, sl, tp, opened_at, closed_at, exit_price, pnl, status, setup_id | Every trade |
| `setups` | external_id, symbol, timeframe, kind (`fvg`/`bpr`/`iofed`...), direction, zone_high, zone_low, detected_at, ml_score, features (JSON) | Detected ICT structures |
| `analyses` | date, symbol, bias, rationale, journal, model, input_tokens, output_tokens, cost_usd | LLM daily analysis |
| `equity_snapshots` | account_id, taken_at, balance, equity, drawdown_pct | For equity curve |
| `engine_logs` | level, message, context (JSON), created_at | Errors and events |
| `heartbeats` | engine_id, beat_at, status, version, open_positions | Liveness |

### Written by Laravel (Python: reads and acknowledges)

| Table | Key columns | Purpose |
|---|---|---|
| `commands` | id, type (`pause`/`resume`/`stop`/`close_all`), requested_by, requested_at, status (`pending`/`done`/`rejected`), handled_at, result | Control requests |
| `settings` | key, value, updated_by, updated_at | Soft parameters (e.g. risk %) the engine validates |
| `users`, standard Laravel tables | | Auth |

### Command protocol
1. Laravel inserts a `pending` command (after authorization and, for `stop`/`close_all`, a confirmation step).
2. Engine polls every few seconds, executes, then sets `done` or `rejected` with a reason.
3. The dashboard shows the status. A command never "succeeds" in the UI until the engine acknowledges it.
4. `stop` and `pause` are always accepted. Anything that would *increase* risk (raising risk %, resuming after a daily-loss halt) is validated by the engine and can be rejected.

## 4. Laravel project layout (suggested)

Separate repository, e.g. `ict-bot-dashboard`.

```
app/
├── Models/               Trade, Setup, Analysis, EquitySnapshot, EngineLog, Heartbeat, Command, Setting
├── Http/Controllers/     DashboardController, TradeController, SetupController, CommandController, SettingController
├── Services/             EngineStatusService (reads heartbeat), PerformanceService (metrics)
├── Jobs/                 SendTelegramAlert, GenerateDailyReport
├── Console/Commands/     (scheduled) CheckEngineOffline, DailyDigest
├── Policies/             CommandPolicy
resources/views/ (or Inertia/Livewire pages)
database/migrations/      source of truth for the shared schema
routes/web.php
```

Recommended stack: Laravel 11+, Breeze for auth, Livewire or Inertia for UI, Chart.js or ApexCharts for charts, Redis or database queue. Pick what you already know best; the dashboard is not the learning goal.

## 5. Pages and features

| Page | Shows | Source |
|---|---|---|
| Overview | Balance, equity, today PnL, open trades, engine status | `equity_snapshots`, `trades`, `heartbeats` |
| Trades | Filterable table, trade detail with linked setup | `trades`, `setups` |
| Setups | Detected structures, ML score, features | `setups` |
| Performance | Equity curve, drawdown, win rate, profit factor, by session | computed in `PerformanceService` |
| Daily journal | LLM analysis per day | `analyses` |
| Controls | Pause / resume / emergency stop, command history | `commands` |
| Settings | Risk parameters | `settings` |
| Logs | Errors and events | `engine_logs` |

Performance metrics are calculated from stored trades, not stored separately, so there is one source of truth.

## 6. Alerts and scheduled jobs (Laravel strengths)

- **Offline alert:** scheduled job checks `heartbeats`; if no beat for N minutes, send Telegram/email.
- **Loss alerts:** on a new `engine_logs` entry with level `critical`, or daily-loss halt.
- **Daily digest:** scheduled job sends the day's PnL and the LLM journal.
- Run alerts through the queue so the dashboard stays fast.

## 7. Security checklist

- Auth required everywhere; roles: `admin` (controls and settings) and `viewer` (read-only).
- Re-confirm password or 2FA for `stop`, `close_all`, and settings changes.
- HTTPS only; secure session cookies; CSRF is on by default, keep it on.
- DB: TLS, IP allowlist, separate users. The Laravel user gets write access to `commands` and `settings` only.
- Secrets in `.env` on each side; never in the repo. Broker credentials exist **only** on the engine machine, never in Laravel or the database.
- Log every control action with user and time.
- The dashboard cannot place, modify or close individual orders. `close_all` is a request the engine executes.

## 8. Python side of the contract

When this phase starts, add to the Python repo:
- `src/ict/store/` with SQLAlchemy Core table definitions mirroring Laravel's migrations, and a writer with idempotent upserts keyed on `external_id`.
- `src/ict/control/` with a command poller and heartbeat sender.
- A contract test that fails if the Python table definitions drift from the migrations (compare column names and types against a test database).
- Update `PROJECT-STRUCTURE.md` and the Dependencies table (`sqlalchemy`, a MySQL/Postgres driver).

## 9. Build order

1. Migrations for the shared tables (Laravel).
2. Python writer that inserts sample trades and setups into a dev database.
3. Read-only pages: Overview, Trades, Setups, Performance.
4. Heartbeat plus engine-status indicator.
5. Command table, command poller in Python, Controls page.
6. Alerts and daily digest.
7. Hardening: roles, 2FA, audit log, backups.

Do steps 1-4 before any control features. A read-only dashboard is already useful and carries no risk.

## 10. Learning checklist (new skills)

- SQLAlchemy Core: tables, inserts, upserts, transactions (compare to Eloquent / query builder).
- Idempotency: why `external_id` and unique keys matter.
- Polling vs push, and why polling is safer here.
- Database users and privileges.
- Optional: FastAPI for a read-only status endpoint.

### Laravel concepts to learn on this project

Goal of this build is also learning, so each new concept is tied to a real feature.

| Concept | Where it fits here | Build step |
|---|---|---|
| **Socialite (OAuth login)** | "Sign in with Google/GitHub" for admin and viewer users, restricted to an email allowlist | 3 |
| **Sanctum API tokens** | Token auth for an HTTP ingest API (`POST /api/engine/trades`) as an alternative to the engine writing to the DB directly. Token abilities (`ingest`, `read`) show scoped permissions | 4 |
| **JWT (`php-open-source-saver/jwt-auth` or `firebase/php-jwt`)** | Short-lived signed tokens for the engine to authenticate to the API; compare trade-offs with Sanctum (stateless, expiry, refresh, revocation) | 4 |
| **Passport (OAuth2 server)** | Optional spike only. Full OAuth2 server is overkill for one engine and one dashboard, but worth a small experiment to see grant types | optional |
| **Fortify / 2FA** | Required re-auth and 2FA for `stop`, `close_all`, settings | 7 |
| **Gates and Policies, roles** | `admin` vs `viewer`, `CommandPolicy` | 5 |
| **Form Requests, API Resources** | Validation of ingest payloads; consistent JSON output | 4 |
| **Rate limiting, middleware** | Throttle the ingest API and login | 4 |
| **Queues, scheduler, notifications** | Telegram/email alerts, daily digest, offline check | 6 |
| **Events and broadcasting (Reverb)** | Live equity and trade updates without refresh | after 6 |
| **Testing (Pest), factories** | Feature tests for auth, policies, ingest API, command flow | all |

Suggested order for auth: start with Breeze (session auth you already know), add Socialite, then Sanctum tokens for the ingest API, then JWT as a comparison exercise. Write down for each: what is stored, how it is revoked, what happens if it leaks.

## 11. Decisions to confirm

| Decision | Recommendation |
|---|---|
| Database | PostgreSQL or MySQL, managed, reachable from both machines over TLS |
| Schema owner | Laravel migrations |
| Control channel | Command table polled by Python (no inbound port) |
| UI stack | Whatever you already use fastest (Livewire or Inertia) |
| Scope | Read-only first; controls later. Price as a separate item if built for a client |
