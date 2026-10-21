# Learning Log

A running record of what was built, which packages were installed, what each piece of code does, and what was learned. Newest entries go at the bottom. Claude appends to this file after each step (rule in CLAUDE.md).

---

## 1. Project setup

- **Docs:** replaced the default Laravel README; design lives in `08-Laravel-Dashboard-Reference.md`.
- **Database:** switched from SQLite to MySQL (`trading_dashboard`) because the Python engine and Laravel must share one network-reachable database. Tests still use in-memory SQLite (set in `phpunit.xml`), so they never touch MySQL data.
- **Commands:**
  ```bash
  mysql -u root -e "CREATE DATABASE trading_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  php artisan migrate:fresh
  ```
- **Learned:** `.env` sets the real DB; `phpunit.xml` overrides it for tests. `migrate:status` shows which migrations ran and their batch number.

## 2. Authentication with Breeze

- **Package:** `laravel/breeze` (dev)
  ```bash
  composer require laravel/breeze --dev
  php artisan breeze:install    # Livewire (Volt Class API) + Alpine, Pest
  php artisan migrate
  ```
- **What it adds:** login, register, password reset, email verification, password confirmation, profile page, and 26 Pest tests.
- **Why Livewire + Volt class API:** dashboards need tables, filters and polling without hand-written JavaScript; class API keeps PHP and Blade in one file.
- **Learned:** a test is one `✓`; an assertion is one check inside it. `php artisan test` is the safety net: run it after every change.

## 3. Roles and gates (admin / viewer)

- **Goal:** only admins may use controls (pause, stop, close all); viewers are read-only.
- **Migration** `add_role_to_users_table`: adds `role` string, default `viewer` (safe default). `down()` must use `$table->dropColumn('role')`, not `Schema::dropIfExists` (that drops a table).
- **`User` model:** `'role'` added to `#[Fillable]`; `isAdmin(): bool` returns `$this->role === 'admin'`.
- **Gate** in `AppServiceProvider::boot()`: `Gate::define('manage-engine', fn (User $user) => $user->isAdmin());`. Import the facade `Illuminate\Support\Facades\Gate`, not `Illuminate\Auth\Access\Gate`.
- **Security note:** because `role` is mass-assignable, never pass raw request input to `User::create()` / `update()`.
- **Concepts:** gates (closure-based yes/no permission checks), mass assignment, safe defaults.

## 4. First own test: the role gate

- **Command:** `php artisan make:test RoleGateTest --pest` creates `tests/Feature/RoleGateTest.php`.
- **Tests:** an admin passes `Gate::forUser($user)->allows('manage-engine')`; a viewer (no role given, so the DB default applies) does not. Result: 2 passed.
- **Migration:** `php artisan migrate` ran `add_role_to_users_table` as batch 2 on MySQL.
- **Concepts:** Pest `it('...', fn)` and `expect(...)->toBeTrue()`; model factories with overrides; tests run on in-memory SQLite, so they pass even if MySQL was never migrated. Always run `migrate` too.
- **Open item:** `down()` in the role migration still calls `Schema::dropIfExists('role')`; it should be `$table->dropColumn('role')`. Verify with `php artisan migrate:rollback` then `migrate` (safe now, no real data).

## 5. First shared table: heartbeats

- **Command:** `php artisan make:model Heartbeat -m` creates the model and its migration; `php artisan migrate` ran it (batch 3).
- **Columns:** `id` (BIGINT UNSIGNED PK), `engine_id` (VARCHAR 64, indexed), `beat_at` (TIMESTAMP, indexed, UTC), `status` (VARCHAR 20), `version` (VARCHAR 32), `open_positions` (SMALLINT UNSIGNED, default 0), `timestamps()`.
- **Why:** the engine inserts a row every few seconds; the dashboard shows "offline" if the latest `beat_at` is too old. Indexes keep "latest beat per engine" fast as rows pile up. Columns are NOT NULL so bad rows are rejected by the database.
- **Concepts:** column types and lengths, `->index()`, NOT NULL vs nullable, app timezone stays UTC. Heartbeats are not trading records, so old rows may be pruned by a scheduled job later.
- **Open item:** `Heartbeat` model is still empty; it needs `#[Fillable]` and a `beat_at` datetime cast.
