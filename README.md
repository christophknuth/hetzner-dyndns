# Hetzner Dynamic DNS Updater

## Concept
`hetzner_dyndns.php` keeps Hetzner DNS records in sync with a live client IP. It detects when the IPv4/IPv6 values change, stores the latest record IDs in SQLite and retries failed updates via a cron-safe path so no change is lost.

## Tech stack
- PHP (with `curl` + SQLite3) for the HTTP/cron endpoint and persistence.
- SQLite database (`hetzner_dyndns.sqlite3`) stores the hostname-to-record metadata plus retry state.
- Hetzner Console API (`api.hetzner.cloud/v1`). The legacy `dns.hetzner.com/api/v1` API has been shut down by Hetzner and support for it was removed.

## Usage
1. Place `hetzner_dyndns.php` and `hetzner_dyndns.config.php.dist` into the document root, then copy the `.dist` file to `hetzner_dyndns.config.php`.
2. Configure authentication credentials and realms in `hetzner_dyndns.config.php` (`auth_user` and `auth_password`; user defaults to `update` if omitted).
3. Point your client at `hetzner_dyndns.php` with `?hostname=...&myip=...&realm=<name>` and authenticate via HTTP Basic (or legacy `X-Authentication` / `p` parameter if your client only supports a shared password).
   *Note: `.htaccess` rewrites `/nic/update` and `/v3/update` to `hetzner_dyndns.php`, so you can also keep using the original DynDNS-style endpoints.*
4. Schedule a cron job such as:
   ```
   */5 * * * * php /path/to/hetzner_dyndns.php --cron --realm=default
   ```
   so failed attempts are retried automatically; you can also hit `hetzner_dyndns.php?action=cron`.

## Docker (Dockhand stack from GitHub)
- The repository doubles as a compose stack: `compose.yaml` builds `Dockerfile` (PHP-FPM) and runs `caddy:2-alpine` in front of it. Import the repository in Dockhand and it clones, builds and starts everything — the build context is the checkout, so the image always matches the deployed commit.
- Before the first start, place your filled-in `hetzner_dyndns.config.php` on the Docker host (copy `hetzner_dyndns.config.php.dist`) and set `DYNDNS_CONFIG` to its absolute path, either in a `.env` next to `compose.yaml` or as a stack environment variable. See `.env.example`. Compose aborts with a readable message if it is unset. Keep the `history_db` line from the template: the script has no built-in default, and without it every request silently starts with an empty temporary database.
- `DYNDNS_HTTP_PORT` (default `8080`) is the host port Caddy publishes. Point your router at `http://<nas>:<port>/nic/update?...`.
- The sqlite database lives in the named volume `dyndns-data` and survives image rebuilds. Inside the container the code directory is read-only; the default database path is a symlink into that volume.
- `.htaccess` is an Apache mechanism and is inert under FPM — the `Caddyfile` takes over that job. It is an allowlist: only `/nic/update` and `/v3/update` reach PHP, everything else (config, database, README, anything added later) is a 404. Do not add `file_server` to it.

## Command-line helper
- `hetzner_dyndns_listhosts.php` reads the same sqlite history database and prints each hostname alongside the current IPv4/IPv6 values, the configured realm, whether the row is pending, and when it was last updated.
- Run it only from the shell (`php hetzner_dyndns_listhosts.php`) so you can quickly audit what IPs are stored without invoking the HTTP endpoint.

## Configuration
- `hetzner_dyndns.config.php.dist` is the committed template; copy it to `hetzner_dyndns.config.php` and fill in the real values.
- Include shared metadata such as `auth_user` / `auth_password` (or just `auth_password` if you stick with the default `update` username), `history_db`, `default_realm`, and each realm’s `console_token`/TTL, plus the optional `auth_realm` label.
- Keep the `history_db` key. Without it the script falls back to `hetzner_dyndns.sqlite3` next to itself — fine on a plain install, but in a container the code directory is read-only, so point it at your data volume.
- Upgrading from an older version: `dns_token`, `dns_endpoint`, `api_order` and `lock_wait_seconds` are no longer read and can be deleted; existing configs keep working unchanged. Existing sqlite databases need no migration — the unused `recordA_id`/`recordAAAA_id` columns are simply left in place.
- Optionally add `'zone_name' => 'your-zone.example.com'` per realm when the DNS zone sits under a subdomain; otherwise the updater infers the domain automatically.
- Add or adjust realms, tokens, and TTL values in that file, then rerun the cron to refresh pending rows.

## Firewall sync (optional)
- Add a `firewall` block to a realm to keep an existing Hetzner Cloud Firewall rule pointed at the current dynamic IP (e.g. so a service port stays reachable only from home). It uses the same `console_token`/project as the Console API updates.
- Configure `enabled`, `firewall_name` (or `firewall_id`), `port`, `protocol` (default `tcp`), and preferably `rule_description`: only inbound rules matching protocol + port — and, when set, exactly that description — get their `source_ips` replaced with the new IPv4/32 (plus IPv6/128 when present). Other rules on the same port stay untouched.
- The rule must already exist; the script updates it but never creates one. Because Hetzner's `set_rules` action replaces the whole rule set, the script always sends every other rule back unchanged.
- If no `rule_description` is set and more than one inbound rule matches the protocol and port, the update is **refused** with an explicit message instead of rewriting all of them — that would silently hand somebody else's access to this client.
- A failed firewall update marks the host as pending (HTTP 503), so the next client poll retries both the record and the firewall even if the IP has not changed again.

## Notifications
- Enable `'notifications.enabled' => true` to send an email after each update attempt.
- Set `'notifications.method'` to `'php'` to use `mail()` or `'smtp'` to speak directly to an authenticated SMTP server.
- Provide at least one recipient via `'notifications.recipients'` or use `'success_recipients'`/`'failure_recipients'` when you need different audiences per outcome.
- Control which events trigger mail with `'send_on_success'` and `'send_on_failure'` (defaults are `false`/`true`).
- Include SMTP credentials under `'notifications.smtp'` when `'method' => 'smtp'` so the script can authenticate and relay updates.

## Debug logging
- Enable debug mode (`debug => true`) to emit zone-lookup steps, HTTP requests, and API responses to PHP’s error log (web server log or CLI stderr). This makes it easier to confirm which zone name is used, see HTTP status codes, and inspect any error payload the Hetzner APIs return.
- Optionally set `debug_log` to a writable file path so the script writes those diagnostics there instead of the default `error_log()` target; this is handy on shared hosting where you may not know where PHP’s error log lives.

## Notes
- The script protects against concurrent access with SQLite pragmas and retry counters, so repeated failures are surfaced through the cron summary output.
- Keep the `history_db` file writable by the PHP process (defaults to `hetzner_dyndns.sqlite3` next to the script).
- `.htaccess` blocks dotfiles and sensitive suffixes (`.sqlite3`, `.log`, `.ini`, `.env`, `.dist`, …) by extension rather than by literal filename, so the database and files added later are covered without further edits. It is an Apache mechanism and does nothing under PHP-FPM — there, the `Caddyfile` takes over. 
- `.htaccess` blocks direct access to `hetzner_dyndns.php`, any other php script and related secrets while allowing DynDNS-standard paths (`/nic/update`, `/v3/update`) to proxy into the script with query parameters intact.

[![Donate](https://img.shields.io/badge/Donate-PayPal-blue.svg)](https://www.paypal.me/cknuth1)
