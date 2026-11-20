# ShellSeek

ShellSeek is a web application for **LLM-assisted web reconnaissance (footprinting and scanning)** with an
independent **Scope Enforcer** in front of every command.

It is the application part (VM 1) of the thesis project
*"Implementation of a Scope Enforcer in LLM-Based Web Penetration Testing Using a Local LLM"*.

A user types an instruction in natural language. A **local LLM** (Ollama, `deepseek-coder:6.7b`) turns it
into a structured command. That command is **never trusted**: it is sent to the **Scope Enforcer**
(a separate FastAPI service on VM 2), which alone decides **ALLOW**, **WARNING** or **DENY**. Only a
signed, unexpired ALLOW for exactly that command may be executed.

Scope: footprinting and scanning on an isolated lab with authorized targets. **No exploitation features.**

> **Current status (2026-11-18)**
> - The Scope Enforcer's policy (which tools, actions and flags are ALLOW / WARNING / DENY) is still being
>   researched. ShellSeek does not depend on its content.
> - **Execution is disabled.** The HMAC signature verifier is not installed yet (it needs the backend's
>   `signing.py`, test vectors and the signing secret), so every ALLOW is shown but blocked by the gate.
> - **WARNING is always held** and never executed.
> - Real integration tests against VM 2 are postponed; only `GET /health` has been checked.

---

## Roles

| Component | May | May not |
|---|---|---|
| LLM (Ollama, local) | Propose one structured command | Set or change scope rules, grant permission |
| ShellSeek (this app, Laravel) | Orchestrate the flow, show the UI, record history, run **authorized** commands | Make scope decisions, override the Scope Enforcer |
| Scope Enforcer (VM 2) | Decide ALLOW / WARNING / DENY, issue signed authorizations | Run tools or the LLM |
| Signed-in user (all accounts equal, no roles) | Run reconnaissance, see and manage **their own** logs | See other accounts' logs, bypass the Scope Enforcer — every command of every user is still validated |

## How a request flows

```
instruction ─▶ LLM (127.0.0.1:11434) ─▶ structure check ─▶ Scope Enforcer (VM 2)
                                                             │
              ┌──────────────────────────┬───────────────────┼─────────────────────┐
              ▼                          ▼                   ▼                     ▼
            ALLOW                     WARNING              DENY        unreachable / timeout / 401 /
              │                     held, shown        reasons shown   413 / 500 / bad JSON / bad schema
              ▼                                                          => treated as DENY
     ExecutionGate: signature, expiry (≤ 60 s), exact command match
              │   expired? -> re-validate once with a new request_id
              ▼
     ExecutionService: argv array, no shell, timeout, 1 MB output cap
```

Every step is written to the activity history **before** the next step starts. If the history cannot be
written, the flow stops and nothing is executed. See [docs/architecture.md](docs/architecture.md).

## Requirements

- PHP 8.2+ (developed on 8.4) with `pdo_sqlite`, `mbstring`, `curl`, `openssl`, `posix`
- Composer 2
- [Ollama](https://ollama.com) listening on `127.0.0.1:11434` with the model `deepseek-coder:6.7b`
- `nmap` and `whois` at `/usr/bin` (only used once execution is enabled)
- Network access to the Scope Enforcer (default `http://192.168.140.20:8000`)
- Node/npm are **not** required (plain CSS/JS in `public/`)

## Installation

```bash
cd ~/shellseek-app
composer install
cp .env.example .env          # then adjust if needed
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

### Secrets

Secrets are **never** stored in `.env`, the database, logs or the UI. Only their **file paths** are
configured, and the files are read at runtime:

| Variable | Default | Content |
|---|---|---|
| `SCOPE_ENFORCER_TOKEN_FILE` | `/home/dio/.se_token` | Bearer token for `POST /api/v1/validate` |
| `SCOPE_ENFORCER_SIGNING_SECRET_FILE` | `/home/dio/.se_signing_secret` | HMAC secret used to verify authorizations |

Both files must belong to the user running the app and have mode `600` (`chmod 600 <file>`); files with
group/other permissions are rejected (fail-closed). The Settings page shows only presence and mode.

### Other configuration

| File | Purpose |
|---|---|
| `config/scope_enforcer.php` | Base URL, timeouts (`SCOPE_ENFORCER_*` in `.env`), fixed `requested_by`, 60 s TTL, 16 KB limit |
| `config/llm.php` | Ollama URL (must be loopback), model, timeouts, temperature 0 |
| `config/tools.php` | **Provisional tool catalog**: action, target kind and LLM hint per tool, plus the absolute binary and timeout used for execution. Edit it when the research is final — no code change needed. It is not a policy. |
| `config/execution.php` | Global max timeout (300 s), output cap, child environment (`PATH`, `LANG` only) |
| `APP_TIMEZONE` | Display timezone (default `UTC`; `.env.example` suggests `Asia/Jakarta`) |
| `HISTORY_MANAGEMENT_ENABLED` | Archive/Delete buttons on the Log page (default `true`; `false` = read-only history) |
| `HISTORY_STALE_AFTER_SECONDS` | A running request is locked against archive/delete until finished or this old (default `900`) |
| `config/auth_app.php` / `REGISTRATION_ENABLED` | Self-registration on/off (default `true`; `false` = `/register` answers 404) and login/registration attempt limits |

## Running

```bash
composer run serve-local      # http://127.0.0.1:8000 (loopback only)
```

Every page requires a signed-in, **active** account (see below). Login is not a reason to expose the app:
there is no HTTPS, so keep binding it to `127.0.0.1`.

**First run:** open `http://127.0.0.1:8000/register` and create your account, then activate it on VM 1:

```bash
php artisan shellseek:users                    # list accounts and their status
php artisan shellseek:user-activate <username> # allow the account to sign in
```

There are no built-in accounts or default credentials.

UI: a desktop-assistant style layout (sidebar, full-width conversation, composer at the bottom)
in a flat, square, monochrome theme (monospace UI, sans only for explanatory text; blue only for the logo, the send button and the composer accent;
decision badges keep their colours). Main navigation (in this order): **Recon Workspace**, **Dashboard** and **Log**
(`/history`: timeline of every request – prompt, generated command, ALLOW/WARNING/DENY, execution status;
filter by decision and outcome; per entry **Archive** / **Restore** / **Delete**, an *Archived* tab, and the
Dashboard counts follow immediately – see `config/history.php`). Recon Workspace opens a start screen (logo with a centred composer); after submitting, the stored result
(`/recon/{id}`, also linked from the Log) shows the instruction, the generated command, the Scope Enforcer verdict
(ALLOW green, WARNING yellow, DENY red) and, only if something was executed, the terminal output.
Secondary pages are linked from the sidebar footer: **Validation Results**, **System Status** (Scope Enforcer + Ollama health, fail-closed mode, signature verifier state) and
**Settings** (read-only). Health (Scope Enforcer, local LLM, execution, fail-closed mode) is shown on the
**Dashboard** together with request counts and recent activity; the sidebar shows no status and never
triggers a health check.

## Accounts & access control

Login controls **who may use this web application**. It does **not** replace the Scope Enforcer: every
command of every signed-in user is still validated by VM 2, and the user identity is stored only in the
local database — it is never sent to VM 2 (`requested_by` stays `laravel-app`).

There are **no roles and no admin pages**: every account is equal, runs reconnaissance and sees only its
own requests (Log, Dashboard, Validation Results, result pages; other accounts' entries answer 404). Each
account may archive, restore and delete only its own log entries (if `HISTORY_MANAGEMENT_ENABLED`).

- **Registration:** name, **username** (3–32 characters: `a-z 0-9 . _ -`, stored lowercase, unique) and
  password. No email (offline lab, no mail). Every new account is **inactive** — including the first — until
  someone with a shell on VM 1 runs `php artisan shellseek:user-activate <username>`. So nobody who can reach
  `/register` can use the scanners on their own. `REGISTRATION_ENABLED=false` turns registration off (404).
- **Account status (VM 1 only):** `shellseek:users`, `shellseek:user-activate <username>`,
  `shellseek:user-deactivate <username>`. A deactivated account is signed out on its next request. Changes are
  logged by account id only; passwords are never shown.
- **Passwords:** at least 12 characters with letters and numbers (max 72, the bcrypt limit), stored only as a
  hash, never logged or flashed back to forms.
- **Login:** username + password; 5 attempts per minute per username + IP; the same message ("Invalid username
  or password.") for an unknown username and a wrong password; inactive accounts are told they are not
  activated yet and are not signed in. The session id is regenerated on login; logout (POST only) invalidates
  the session and the CSRF token. No "remember me". Session cookie: HttpOnly, SameSite=Lax.
- **Routes:** everything except `/login`, `/register` and `/up` is behind `auth` + `active`. A guest or inactive
  account never reaches the pipeline. A test fails if a new route forgets this middleware.
- **Forms:** CSRF everywhere, server-side FormRequest validation, unknown fields (e.g. `is_active`, `role`,
  `email`) rejected; `is_active` is not mass-assignable.

**Honest limits.** Accounts control access to this application only, not to the machine. Anyone with a
shell (or root) on VM 1 can read the SQLite database, activate accounts, run tools directly or bypass the
web UI entirely; protecting VM 1 itself (OS accounts, disk, firewall) is outside this application. There is
no password reset (no mail): a forgotten password means deactivating the account and registering a new one.
There is no TLS, which is why the app stays on loopback.

## Tests

```bash
./vendor/bin/pest                    # full suite (no network, no real tools)
./vendor/bin/pest --group=ollama     # opt-in: one real request to the local Ollama (~20 s)
```

- Every test runs with `Http::preventStrayRequests()`: nothing can reach VM 2, Ollama or the internet
  unless it is faked. The only exceptions are real-socket timeout tests on `127.0.0.1` and the opt-in
  Ollama test.
- Real `nmap`/`whois` never run in tests. Process tests use a fake PHP script (`tests/Fixtures/fake-tool.php`).
- Architecture tests forbid `exec`, `shell_exec`, `system`, `passthru`, `popen`, `proc_open`, backticks,
  Symfony Process outside `SymfonyProcessRunner`, and `AuthorizedCommand` creation outside `ExecutionGate`.

## Security model (summary)

- **Default-deny, fail-closed** at every step. Anything other than *HTTP 200 + ALLOW +
  `execution_allowed: true` + valid authorization* is DENY.
- **Execution** requires a backend ALLOW whose HMAC-SHA256 authorization is valid, unexpired (≤ 60 s) and
  bound to the identical `tool`, `action`, `target` and `parameters`.
- **No shell**: an argument array is passed to Symfony Process; metacharacters are literal.
- **Untrusted LLM output** is validated for structure only and forwarded unchanged (never "fixed" to pass).
- **Secrets** are read from mode-600 files and masked (`********`) in history, logs and pages.
- **Never run as root.** The execution service refuses an effective uid of 0.
- **Accounts** gate access to the app (fail-closed `auth` + `active` on every application route); they never
  replace or influence the Scope Enforcer's decision.

## Deviations from the research document

- **Laravel 12** instead of Laravel 11: Laravel 11 no longer receives security fixes (ended March 2026).
- **Login** (username + password, no roles; accounts activated on VM 1 via artisan) was added on top of the
  research design, using only Laravel's built-in session guard, hashing, rate limiter and password rule
  (no Breeze, Jetstream or Fortify).

## Pending (outside this repository)

- Backend `signing.py` copy, signature test vectors (`docs/signing/`) and the signing secret file →
  enables the HMAC verifier and therefore execution.
- Final Scope Enforcer policy and approved lab target(s) → real integration scenarios.
- A backend mechanism for human approval of WARNING (the extension point `WarningApprovalPolicy` exists,
  disabled).
