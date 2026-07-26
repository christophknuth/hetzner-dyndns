# AGENTS.md

Instructions for coding agents working in this repository. Human contributors
may find it useful too; `README.md` covers installation and configuration.

## Project overview

A dependency-free DynDNS endpoint for Hetzner DNS: three PHP files copied into a webroot. There is no build step, no package manager, no test suite, and no linter config.

The repository doubles as a compose stack (`compose.yaml` + `Dockerfile` + `Caddyfile`) for import into a Docker stack manager straight from GitHub — see "Container deployment" below. The PHP files remain deployable on plain Apache as well; `.htaccess` serves that path only.

## Commands

```bash
php -l hetzner_dyndns.php                          # syntax check (the only automated gate)
php hetzner_dyndns.php --cron --realm=default      # run the retry queue; prints a per-host summary
php hetzner_dyndns_listhosts.php                   # dump the sqlite history table as a table
```

Both scripts require `hetzner_dyndns.config.php` to exist next to them (copy from `.dist`); the CLI helper additionally requires the sqlite file to already exist.

Local HTTP testing is awkward by design: `.htaccess` returns 403 for any direct request to `hetzner_dyndns.php`, and `php -S` ignores `.htaccess` entirely. Two workable routes:

```bash
# 1. The container stack — closest to production (Caddy + PHP-FPM).
DYNDNS_CONFIG=/abs/path/to/hetzner_dyndns.config.php docker compose up -d --build
curl -u update:PASSWORD 'http://localhost:8080/nic/update?hostname=foo.example.com&myip=1.2.3.4&realm=default'

# 2. A real Apache host, hitting the rewritten endpoints.
curl -u update:PASSWORD 'https://host/nic/update?hostname=foo.example.com&myip=1.2.3.4&realm=default'
```

When verifying behaviour against the Hetzner API, prefer a local mock over live credentials: point `console_endpoint` at a stub server and assert on the request bodies. The `set_rules` payload in particular is worth asserting on — see the firewall note below.

## Architecture

**Two entry paths, one file.** The top of [hetzner_dyndns.php](hetzner_dyndns.php) is procedural code that executes on include; everything below the `/* Helpers */` marker is functions. `get_cron_context()` decides between the cron branch (CLI `--cron` or HTTP `?action=cron`) and the normal update branch.

**Bootstrap order is load-bearing:** exception handler → config → `authenticate()` → `new DDnsDB()`. Opening the database first meant an unusable file emitted PHP warnings to *unauthenticated* callers, and that early output made every later `header()`/`http_response_code()` a no-op — the 401 silently became a 200. Keep authentication ahead of anything that can produce output. `DDnsDB` enables SQLite exceptions so a corrupt or unwritable database becomes a clean 500 instead of a warning cascade with a failed write reported as `good`; it is never switched back, because `enableExceptions(false)` is deprecated in PHP 8.3 and the notice would itself start output.

**Realms are the multi-tenant unit.** Config returns a plain PHP array; each entry in `realms` carries its own TTL, API endpoint tokens, and preferred order of DNS providers. `get_realm_config()` is the single place where realm defaults are applied — add new per-realm settings there, and mirror them in `hetzner_dyndns.config.php.dist`.

**One backend: the Hetzner Console API** (`api.hetzner.cloud/v1`, `Authorization: Bearer`). The legacy `dns.hetzner.com/api/v1` backend was removed after Hetzner shut it down; configs may still carry `dns_token`/`dns_endpoint`/`api_order`, which `get_realm_config()` logs once and ignores.

The API addresses **rrsets by name+type**, not individual records by ID, and writes via `POST /zones/{id}/rrsets/{name}/{type}/actions/set_records`. Two consequences:

- It never **creates** missing rrsets — `update_via_console_api()` fails naming the type and zone. The A/AAAA rrsets must pre-exist.
- `console_set_rrset()` maps the apex name `@` to `_` in the URL path.

`build_rrset_candidates()` generates progressively shorter names (`a.b.c` → `a.b` → `a` → `@`) and `choose_rrset_name()` picks the first the zone actually exposes, absorbing the difference between zone-relative and fully-qualified naming.

**API errors are propagated, not swallowed.** `fetch_console_zone_id()` and `fetch_console_rrsets()` return the `['success' => bool, …]` shape so a 401 surfaces as an auth error instead of a missing zone or rrset. `http_request()` additionally treats a 2xx whose body is not JSON as a failure — a retired endpoint answering 200 with HTML would otherwise read as an empty result.

**Optional firewall sync.** When a realm sets `firewall.enabled`, `sync_host()` calls `update_firewall()` after a successful record update. It resolves the firewall by `firewall_id` or `firewall_name`, rewrites `source_ips` of every inbound rule matching protocol + port (and `rule_description`, when set) to the new `/32` + `/128` addresses, and POSTs via `/firewalls/{id}/actions/set_rules`. Three invariants:

- **`set_rules` replaces the entire rule set**, so `normalize_firewall_rules()` must always send every untouched rule back (with `null` fields stripped — icmp rules carry no port). Getting this wrong silently deletes production firewall rules; assert on the full payload when changing this code.
- **Ambiguous matches are refused.** Without `rule_description` the filter is only direction+protocol+port, which can hit several rules (e.g. a static office allowlist on the same port). More than one match without a description aborts the update rather than handing someone else's access to this client. Exactly one match stays allowed.
- The rule must pre-exist; the script never creates one.

A firewall failure sets `needs_sync = 1`, so the next client poll retries record + firewall even with an unchanged IP.

**Zone vs. record name.** `split_hostname()` naively assumes the last two labels are the domain, which is wrong for subdomain zones and multi-part TLDs. The `zone_name` realm override plus `derive_hostname_name_from_zone()` recompute the record name relative to the real zone and override the naive split. Any hostname-parsing change must keep both the HTTP branch and `process_pending_updates()` in sync — they duplicate this sequence.

**SQLite is cache *and* retry queue.** The `history` table (keyed by hostname) stores the last known IPs, the resolved `zone_id`, and `needs_sync`/`retry_count`/`pending_since`. Consequences worth knowing:

- `should_skip_update()` returns early when the IPs match and nothing is pending, so most client polls never touch the Hetzner API.
- A failed update writes the *new* IPs with `needs_sync = 1`. That flag is the retry mechanism: while it is set, even an unchanged-IP poll from the router triggers a fresh sync. Never clear it without a successful update.
- The cached `zone_id` is passed back into the Console API to save a lookup, and **every failure path returns `zone_id => null`** so an id that no longer resolves (zone deleted and recreated) is discarded instead of sticking forever.
- `upsert_history()` writes `''` instead of `NULL` for a missing `zone_id`; `fetch_history_row()` converts it back. Keep that pairing intact.
- `DDnsDB::open()` falls back to `hetzner_dyndns.sqlite3` next to the script when `history_db` is absent. Without that fallback `SQLite3::open(null)` would open a *temporary* database and silently discard all state.
- Schema migrations are self-applied in `DDnsDB::ensure_schema()` — new columns go in both the `CREATE TABLE` and the `$required` map so existing deployments get an `ALTER TABLE`. Columns are never dropped; databases predating the legacy-API removal still carry unused `recordA_id`/`recordAAAA_id`.

**Auth is deliberately permissive** for legacy router firmware: `authenticate()` accepts HTTP Basic, a raw `X-Authentication` header, and `?p=<password>`. CLI runs return early — cron carries no HTTP credentials, and anyone who can execute the script can already read the config. `auth_password` may be a scalar or an array of accepted passwords; `script_password` is the deprecated key name still honored as a fallback. Username defaults to `update` when `auth_user` is unset. A `PHP_AUTH_DIGEST` branch was removed: it compared against `md5("user:password")`, which is not an RFC 7616 response, and it returned early and thereby skipped every working method.

**Container deployment is an allowlist, not a denylist.** `compose.yaml` builds the FPM image from this repository as build context (`COPY`, never `git clone` — the image must not be able to drift from the deployed commit) and puts `caddy:2-alpine` in front. Two invariants:

- The `Caddyfile` has **no `file_server`**. Only `/nic/update` and `/v3/update` are rewritten into `hetzner_dyndns.php`; every other path hits a catch-all 404, so the config, the database and any file added later are unreachable by construction. Adding `file_server` re-exposes them — a `*.sqlite3` leak was verified before this design. `.htaccess` is inert under FPM and protects nothing here; on the Apache path it blocks dotfiles and sensitive **suffixes** rather than literal filenames (an anchored `\.sqlite3` alternative used to miss `hetzner_dyndns.sqlite3` entirely).
- The code directory is read-only for the PHP worker. `/var/www/html/hetzner_dyndns.sqlite3` is a symlink into the `dyndns-data` volume so the stock `history_db` value still works; sqlite resolves it and writes `-wal`/`-shm` there too.

`hetzner_dyndns.config.php` is bind-mounted from the host via `DYNDNS_CONFIG` and is never baked into the image (it carries the API token). `.dockerignore` allowlists only the two application scripts.

**Debug logging relies on globals.** `log_debug()` reads `global $debug` and `global $config`, so it only works after the top-of-file bootstrap has run. Output goes to `debug_log` if set, otherwise `error_log()`.

## Conventions

- Snake_case free functions, one class (`DDnsDB`); no namespaces, no autoloader, no external libraries — `curl`, `SQLite3`, and `stream_socket_client` (the hand-rolled SMTP client) are the only dependencies. Do not introduce Composer or a framework without being asked.
- API helpers return the `['success' => bool, 'message'/'error' => string, ...]` shape produced by `http_request()`; keep new helpers conforming to it so callers can propagate failures without special-casing.
- The HTTP endpoint speaks the DynDNS protocol: `good <ip>` on success, bare tokens like `nohost` on client errors, HTTP 503 plus the error message on API failure. Router firmware parses these bodies — do not "fix" the status codes or wording.
- `hetzner_dyndns.config.php`, `.env` and `*.sqlite3` are gitignored; only the `.dist` template and `.env.example` are committed. When adding a config key, update `.dist` and the README section that documents it.
- Never commit real tokens, passwords, or a populated sqlite database.

## Known issues / limitations

- **`process_pending_updates()` is easy to break again.** The loop variable is named `$syncResult` on purpose ([line 1136](hetzner_dyndns.php#L1136)): `$result` holds the `SQLite3Result` that the `while` condition on [line 1114](hetzner_dyndns.php#L1114) iterates, and reusing that name previously killed every cron run after the first pending host.
- **`max_retry_attempts` only caps a counter.** Nothing branches on `retry_count` or `pending_since`; they are diagnostics. A permanently failing host is retried on every poll forever.
- **The SMTP notifier is minimal** — hand-rolled, `AUTH LOGIN` only, no queueing. Notifications are sent only from the HTTP path, not from `process_pending_updates()`.
- **`split_hostname()` assumes the last two labels are the zone**, which is wrong for multi-part TLDs. The per-realm `zone_name` override exists for exactly that case.
- **No automated tests.** Verification is manual, against a mock of the Console API (see "Commands").
