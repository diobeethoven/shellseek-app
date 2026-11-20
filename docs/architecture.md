# ShellSeek – Architecture

This document describes the ShellSeek application (VM 1). The Scope Enforcer (VM 2) is a separate,
finished service; it is described here only through its API contract.

## 1. Topology

```
Browser ─▶ VM 1: Parrot OS (Host-Only 192.168.140.1)
             ShellSeek (Laravel 12, Blade; username login required) ── SQLite (users, sessions, activity history)
             Ollama 127.0.0.1:11434 (local only)
             nmap / whois (executed only after authorization)
               │
               │ REST + JSON (Bearer token)
               ▼
           VM 2: Ubuntu Server 24.04 — Scope Enforcer (FastAPI) 192.168.140.20:8000
             firewall: port 8000 accepts only 192.168.140.1
```

## 2. Components

| Component | Location | Responsibility |
|---|---|---|
| `ChatController` | `app/Http/Controllers` | `GET/POST /recon`, `GET /recon/{log}`; Post/Redirect/Get to the stored result |
| `ReconPipeline` | `app/Services/Recon` | Orchestrates one request; records each step before the next |
| `LlmService` | `app/Services/Llm` | Ollama `/api/chat`, structure-only JSON schema, loopback guard |
| `CommandParsingService` | `app/Services/Command` | LLM text → `ValidationRequest` (structure only, no fixing); Laravel-owned `request_id` |
| `ScopeEnforcerClient` | `app/Services/ScopeEnforcer` | HTTP client to VM 2; strict response schema; every failure → DENY |
| `ExecutionGate` | `app/Services/Execution` | Decision, binding, lifetime and signature checks → `AuthorizedCommand` |
| `SignatureVerifier` | `app/Services/Execution` | Interface; currently bound to `UnavailableSignatureVerifier` (always refuses) |
| `ExecutionService` | `app/Services/Execution` | Runs an `AuthorizedCommand`: catalog binary, argv only, timeout, output cap |
| `SymfonyProcessRunner` | `app/Services/Execution` | The only class that starts processes |
| `HistoryLogService` | `app/Services/History` | Writes `activity_logs`; masks secrets via `SecretMasker` |
| `SystemStatusService` | `app/Services/Status` | Health of VM 2 and Ollama (informational, cached 15 s) |
| `SecretFileReader` / `Secret` | `app/Support` | Reads mode-600 secret files; value never printable |
| `LoginController` / `RegisterController` | `app/Http/Controllers/Auth` | Login, logout (POST), registration (404 when disabled) |
| `LoginRequest` / `RegisterRequest` | `app/Http/Requests/Auth` | Username + password, server-side validation, English messages, unknown fields rejected, login throttle |
| `UserRegistrar` | `app/Services/Auth` | Creates accounts, always inactive |
| `AccountStatus` | `app/Services/Auth` | Activate / deactivate (used only by the artisan commands; logged by id) |
| `ListUsers`, `ActivateUser`, `DeactivateUser` (`shellseek:users`, `shellseek:user-activate`, `shellseek:user-deactivate`) | `app/Console/Commands` | Account status on VM 1 (no web page) |
| `EnsureUserIsActive` | `app/Http/Middleware` | `active` middleware: inactive account → session ended, redirect to login / 403 |
| `ActivityLogPolicy` | `app/Policies` | `view` and `manage` (archive/restore/delete): own logs only |

## 3. One request, step by step

0. **Access** – the request passes `auth` and `active` (signed-in, active account); otherwise it is
   redirected to `/login` (or 401/403 for JSON) and no service of the pipeline is even constructed.
1. **Record** the instruction (`activity_logs` row, outcome `in_progress`, `user_id` = the signed-in user).
   The user id is stored locally only; the request to VM 2 keeps exactly the six contract fields with
   `requested_by = laravel-app`.
2. **LLM** – `LlmService::generateCommand()`; the system prompt is built from `config/tools.php`.
   Failure (Ollama down, timeout, 404 model, truncated output…) → outcome `llm_failed`, nothing sent to VM 2.
3. **Structure** – `CommandParsingService::parse()` accepts only a pure JSON object with exactly
   `tool`, `action`, `target` (strings) and `parameters` (list of strings). No trimming, lowercasing,
   splitting, coercion or markdown extraction. Failure → `parse_failed`, nothing sent to VM 2.
4. **Validate** – `ScopeEnforcerClient::validate()` sends the command unchanged.
   - `WARNING` → `warning`, held.
   - `DENY` → `denied`.
   - Any transport/HTTP/schema problem → `fail_closed` (decision DENY, origin `fail_closed`).
5. **Gate** – `ExecutionGate::authorize(validated, result, toExecute)`. If the only problem is an
   expired authorization, the identical command is validated **once** more under a new `request_id`
   (`revalidated`, `original_request_id`). The gate result is recorded **before** execution.
6. **Execute** – `ExecutionService::run(AuthorizedCommand)`; result recorded (`executed`, `timed_out`,
   `output_limit_exceeded`, `execution_refused`, `execution_error`).
7. **Redirect** to `/recon/{id}`, which renders the stored row as a conversation (technical view: `/validations/{id}`).

If writing the history fails at any point, the pipeline stops (`history_error`) and nothing is executed.

## 4. Where DENY happens (fail-closed)

| Layer | Condition | Result |
|---|---|---|
| Client, before sending | token file missing/insecure, body > 16 KB, not JSON-encodable | DENY, request not sent |
| Client, transport | connection refused, DNS failure, connect/read timeout | DENY (`unreachable` / `timeout`) |
| Client, HTTP | any status ≠ 200 (401, 413, 500, 3xx, …), redirects never followed | DENY |
| Client, body | invalid JSON, not an object, missing/mistyped field, unknown decision, `request_id` mismatch | DENY |
| Client, consistency | ALLOW without `execution_allowed`/authorization; WARNING/DENY with them; bad algorithm/signature format | DENY |
| Gate | not a backend ALLOW, command or `request_id` differs, TTL > 60 s, issued in the future (> 5 s skew), expired, signature not valid | not executed |
| Execution | tool not in catalog, binary missing/relative, target empty or starting with `-`, NUL byte, root, expired just before start | not executed |

Retry policy (client): at most **one** retry, only when the TCP connection was never established
(curl errno 6/7, or 28 with `connect_time` 0). Read timeouts and HTTP responses are never retried, to avoid
duplicate entries in the backend history.

## 5. Scope Enforcer API contract (used by the client)

- `GET /health` → `200 {"status":"ok"}` or `503 {"status":"unavailable"}` (no token sent).
- `POST /api/v1/validate`, `Authorization: Bearer <token>`, JSON ≤ 16 KB, all fields required, unknown
  fields rejected:

```json
{"request_id":"REQ-…","tool":"nmap","action":"service_detection","target":"…",
 "parameters":["-sV","-p","80,443"],"requested_by":"laravel-app"}
```

- Response (200 for ALLOW, WARNING and DENY):

```json
{"request_id":"REQ-…","decision":"ALLOW","risk_level":"LOW","execution_allowed":true,"reasons":[],
 "validation":{"command_valid":true,"target_valid":true,"tool_valid":true,"parameters_valid":true},
 "policy_version":"1.0",
 "authorization":{"issued_at":0,"expires_at":0,"algorithm":"HMAC-SHA256","signature":"<64 hex>"}}
```

- `authorization` only on ALLOW, valid 60 s. Signature: HMAC-SHA256 over a length-prefixed canonical
  string (to be implemented exactly from the backend's `signing.py` and verified with its test vectors).

The **content** of the policy (which flags are ALLOW/WARNING/DENY) is decided by the backend and is still
being researched; ShellSeek never encodes it.

## 6. Data model: `activity_logs`

One row per request, updated as it progresses. All text is masked before writing; the HMAC signature
itself is not stored.

| Group | Columns |
|---|---|
| Identity | `id`, `request_id` (unique), `original_request_id`, `revalidated`, `instruction`, `stage`, `outcome` |
| LLM | `llm_model`, `llm_output` (≤ 4 KB), `llm_failure`, `llm_duration_ms` |
| Parse | `parse_failure`, `parse_detail`, `tool`, `action`, `target`, `parameters` (JSON) |
| Decision | `decision`, `risk_level`, `execution_allowed`, `reasons` (JSON), `validation` (JSON), `policy_version`, `origin`, `failure_code`, `authorization_issued_at`, `authorization_expires_at` |
| Gate | `gate_refusal` |
| Execution | `execution_status`, `exit_code`, `argv` (JSON), `stdout`/`stderr` (≤ 64 KB), `execution_duration_ms`, `execution_reason` |
| Ownership | `user_id` (nullable FK to `users`, null on delete; local only, never sent to the Scope Enforcer; null for rows created before login existed) |
| Management | `archived_at` (set = archived: hidden from Log, Dashboard and Validation Results, restorable) |

**Manual history management** (Log page, `HistoryController`): *Archive* sets `archived_at`, *Restore* clears it,
*Delete* removes the row permanently. The Dashboard computes its counts live from non-archived rows, so it is
always in sync. Rows of a request that is still running (`in_progress`, `allowed`, `authorized`) are locked
(HTTP 409) until finished or stale (`HISTORY_STALE_AFTER_SECONDS`, default 900). `HistoryLogService` also treats a
row that vanished mid-run as a write failure, so the pipeline never executes without a history record.
`HISTORY_MANAGEMENT_ENABLED=false` makes the history read-only (routes answer 403).

## 7. Extension points

| Extension | Today | How to enable |
|---|---|---|
| HMAC verification | `UnavailableSignatureVerifier` → nothing executes | Implement `SignatureVerifier` from `docs/signing/` + vectors, bind it in `AppServiceProvider` |
| WARNING approval | `WarningApprovalPolicy` bound to `DisabledWarningApproval` | Needs a backend mechanism that issues a signed authorization; must still pass the gate |
| Tools | `config/tools.php` (nmap, whois, provisional) | Add/edit entries; no code change |

## 8. Access control (accounts)

Accounts control access to this web application only. They are not a policy engine: the Scope Enforcer
remains the sole source of ALLOW / WARNING / DENY for every user, and no user identity is sent to VM 2.

**Public routes:** `/login`, `/register` (only if `REGISTRATION_ENABLED`) and `/up`. Every other route is in
`Route::middleware(['auth', 'active'])` (guarded by `tests/Feature/Auth/RouteProtectionTest.php`). Laravel 12's
default `storage/{path}` routes are disabled (`config/filesystems.php`, `'serve' => false`) because ShellSeek
never serves stored files.

**`users` columns:** `name`, `username` (unique, lowercase, 3–32 characters `a-z 0-9 . _ -`), `password`
(hash) and `is_active` (default `false`). There is no email and no role column: every account is equal.
`is_active` is not in `User::$fillable`; only `AccountStatus` (artisan, VM 1) changes it.

| | Own logs | Other accounts' / ownerless logs | Account status |
|---|---|---|---|
| Any signed-in, active account | view, archive, restore, delete | not visible (404) | — |
| Someone with a shell on VM 1 | — | — | `shellseek:user-activate` / `-deactivate` |

Sessions: database driver, id regenerated on login, invalidated + new CSRF token on logout, HttpOnly,
SameSite=Lax, no "remember me". Login: 5 attempts / minute per username + IP, one message for unknown
username and wrong password (a hash is checked in both cases). Registration: 5 / minute per IP; password ≥ 12 characters
with letters and numbers.

Limits: this protects the web application, not the host. A user with a shell or root on VM 1 can read the
database, activate accounts or run tools directly. No password reset (offline, no mail), no TLS (loopback only).

## 9. Security invariants (tested)

- No shell execution functions or backticks in `app/` (architecture tests).
- Only `SymfonyProcessRunner` uses Symfony Process; only `ExecutionGate` creates `AuthorizedCommand`.
- Child processes get only `PATH` and `LANG`.
- No automated test can reach the network (`Http::preventStrayRequests()` in `tests/TestCase.php`).
- Secrets never appear in history, logs, pages, `.env.example`, `CLAUDE.md` or this documentation.
- Every non-public route requires `auth` + `active`; guests and inactive users never construct a pipeline
  service, send an HTTP request or create a history row.
- The validate request to VM 2 contains no user name, username or id.
