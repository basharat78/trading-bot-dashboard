# Trading Bot Dashboard

A Laravel dashboard for monitoring (and, later, sending control requests to) an ICT trading engine written in Python that trades through MT5.

> **Status:** early scaffold. The Laravel app is freshly installed; the dashboard itself is not built yet. The full design lives in [08-Laravel-Dashboard-Reference.md](08-Laravel-Dashboard-Reference.md).

## How it fits together

Two separate apps share one database contract. They never import each other.

```
 Windows VPS                          Managed DB                  Web server
 ┌──────────────────────┐        ┌────────────────┐        ┌─────────────────┐
 │ MT5 + Python engine  │─write─▶│  MySQL /       │◀─read──│ Laravel         │
 │                      │◀─poll──│  PostgreSQL    │─write─▶│  (this project) │
 └──────────────────────┘commands└────────────────┘commands└─────────────────┘
```

- **Python owns trading.** Laravel can only *request* actions (pause, resume, stop, close all); it never places orders or overrides risk limits.
- **No inbound ports on the trading machine.** The engine polls the `commands` table.
- **Laravel owns the schema** via migrations. Python reads and inserts against those tables.

## Planned features

| Page | Shows |
|---|---|
| Overview | Balance, equity, today's PnL, open trades, engine status |
| Trades | Filterable trade table with linked setup |
| Setups | Detected ICT structures and ML scores |
| Performance | Equity curve, drawdown, win rate, profit factor |
| Daily journal | LLM analysis per day |
| Controls | Pause / resume / emergency stop and command history |
| Settings | Soft risk parameters (validated by the engine) |
| Logs | Engine errors and events |

Alerts (engine offline, critical errors, daily digest) run as scheduled jobs through the queue.

## Build order

1. Migrations for the shared tables
2. Python writer that inserts sample data into a dev database
3. Read-only pages: Overview, Trades, Setups, Performance
4. Heartbeat and engine-status indicator
5. Command table, Python poller, Controls page
6. Alerts and daily digest
7. Hardening: roles, 2FA, audit log, backups

Steps 1-4 come before any control features; a read-only dashboard is already useful and carries no risk.

## Learning goals

This project is also a vehicle for learning new Laravel concepts: Socialite (OAuth login), Sanctum API tokens, JWT, policies and roles, 2FA, queues and notifications, broadcasting, and Pest tests. The mapping of concept to feature is in section 10 of the [reference](08-Laravel-Dashboard-Reference.md).

## Conventions

- Money and prices use `DECIMAL(18,5)` or higher, never floats.
- All timestamps are stored in UTC and converted only for display.
- Engine-written rows carry a unique `external_id` so retries don't duplicate.
- Trading records are never hard-deleted.
- Broker credentials exist only on the engine machine, never in Laravel or the database.

## Local setup

Requires PHP and Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run dev
php artisan serve
```

Configure the database connection in `.env`. Use a dedicated DB user for Laravel with write access limited to `commands` and `settings`.

## Documentation

- [08-Laravel-Dashboard-Reference.md](08-Laravel-Dashboard-Reference.md): architecture, database contract, command protocol, security checklist, build order.
- [AGENTS.md](AGENTS.md) / [CLAUDE.md](CLAUDE.md): guidelines for AI coding agents (Laravel Boost).

## License

Built on Laravel, which is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
