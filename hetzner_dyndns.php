<?php
/*
 * Hetzner DNS Dynamic DNS Script (enhanced)
 *
 * Maintains per-realm configuration in a separate file, retries failed API calls,
 * and supports both the legacy DNS API and the newer Hetzner Console API so updates
 * are never silently dropped.
 *
 * `.htaccess` rewrites `/nic/update` and `/v3/update` into this file while blocking
 * direct requests, so the DynDNS-style paths remain viable long term.
 *
 * The configuration lives in hetzner_dyndns.config.php next to this script. Cron
 * retries can be triggered via `php hetzner_dyndns.php --cron` or the HTTP
 * `?action=cron` endpoint, which keeps history rows flagged when API calls fail.
 */

// Turn bootstrap failures (unreadable config, unusable database) into a plain 500
// instead of a stack trace, and keep the details in the log where they belong.
set_exception_handler(static function (Throwable $e): void {
    error_log('[Hetzner DDNS] ' . $e::class . ': ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Internal error';
});

$config = load_config(__DIR__ . '/hetzner_dyndns.config.php');
$debug = $config['debug'] ?? false;

if (!class_exists('SQLite3')) {
    http_response_code(500);
    exit('SQLite3 support is not available in this PHP installation.');
}

$cronContext = get_cron_context();

// Authenticate BEFORE touching the database: opening it first meant an unusable
// database file emitted PHP warnings to unauthenticated callers, and that output
// sent the headers early so the 401 below could never take effect.
authenticate($config);

$db = new DDnsDB($config);

if ($cronContext['is_cron']) {
    $summary = process_pending_updates($db, $config, $cronContext['realm']);
    echo render_cron_summary($summary);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('HTTP/1.0 405 Method Not Allowed');
    exit('Method Not Allowed');
}

$realmKey = trim($_GET['realm'] ?? get_default_realm_key($config));
if ($realmKey === '' || !isset($config['realms'][$realmKey])) {
    header('HTTP/1.0 400 Bad Request');
    exit('Unknown realm');
}

$realmConfig = get_realm_config($config, $realmKey);
$hostname = trim($_GET['hostname'] ?? '');
if ($hostname === '') {
    header('HTTP/1.0 400 Bad Request');
    exit('nohost');
}

if (!valid_hostname($hostname)) {
    exit('Invalid domain name');
}

$ipSource = $_GET['myip'] ?? resolve_client_ip();
$ips = parse_ip_list($ipSource);
if (!$ips['ipv4']) {
    exit('No valid IPv4 address provided');
}

$split = split_hostname($hostname);
$hostnameName = $split[0];
$domain = $split[1];
$zoneLookupName = get_zone_lookup_name($realmConfig, $domain);
$overriddenHostnameName = derive_hostname_name_from_zone($hostname, $zoneLookupName);
if ($overriddenHostnameName !== null) {
    $hostnameName = $overriddenHostnameName === '' ? '@' : $overriddenHostnameName;
}
$historyRow = fetch_history_row($db, $hostname);
if ($historyRow && $historyRow['realm'] !== $realmKey) {
    $historyRow = null;
}

if (should_skip_update($historyRow, $ips)) {
    $storedIpv4 = $historyRow && isset($historyRow['ip']) ? $historyRow['ip'] : 'n/a';
    $storedIpv6 = $historyRow && isset($historyRow['ip6']) ? $historyRow['ip6'] : 'n/a';
    log_debug(sprintf(
        'Skipping update for %s; stored IPv4=%s IPv6=%s match incoming values',
        $hostname,
        $storedIpv4,
        $storedIpv6
    ));
    echo 'good ' . $ips['ipv4'];
    exit;
}

$result = sync_host($db, $config, $realmKey, $realmConfig, $hostname, $zoneLookupName, $hostnameName, $ips, $historyRow);
send_notification($config['notifications'] ?? [], $realmKey, $hostname, $ips, $result);
if ($result['success']) {
    echo 'good ' . $ips['ipv4'];
    exit;
}

http_response_code(503);
echo $result['message'];
exit;

/* Helpers */
/**
 * Load and validate the shared configuration, ensuring realms are defined.
 */
function load_config(string $path): array
{
    if (!file_exists($path)) {
        throw new RuntimeException('Configuration file not found: ' . $path);
    }

    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Configuration must return an array.');
    }

    if (empty($config['realms']) || !is_array($config['realms'])) {
        throw new RuntimeException('At least one realm must be configured.');
    }

    return $config;
}

function get_default_realm_key(array $config): ?string
{
    if (!empty($config['default_realm']) && isset($config['realms'][$config['default_realm']])) {
        return $config['default_realm'];
    }

    foreach ($config['realms'] as $key => $_) {
        return $key;
    }

    return null;
}

function get_realm_config(array $config, string $realmKey): array
{
    $realm = $config['realms'][$realmKey] ?? null;
    if (!$realm) {
        throw new RuntimeException('Realm not defined: ' . $realmKey);
    }

    $realm['console_endpoint'] = rtrim($realm['console_endpoint'] ?? 'https://api.hetzner.cloud/v1', '/');
    $realm['ttl'] = $realm['ttl'] ?? 60;
    $realm['zone_name'] = trim($realm['zone_name'] ?? '');

    // Leftovers from the retired dns.hetzner.com backend. Old configs keep working;
    // the keys are simply ignored.
    if (isset($realm['dns_token']) || isset($realm['dns_endpoint']) || isset($realm['api_order'])) {
        log_debug(sprintf('Realm %s still defines dns_token/dns_endpoint/api_order; ignored since the legacy DNS API was removed.', $realmKey));
    }

    // Optional Hetzner Cloud Firewall sync; disabled unless explicitly enabled.
    $firewall = (array) ($realm['firewall'] ?? []);
    $realm['firewall'] = [
        'enabled' => !empty($firewall['enabled']),
        'firewall_id' => $firewall['firewall_id'] ?? null,
        'firewall_name' => trim((string) ($firewall['firewall_name'] ?? '')),
        'port' => trim((string) ($firewall['port'] ?? '')),
        'protocol' => strtolower(trim((string) ($firewall['protocol'] ?? 'tcp'))),
        'rule_description' => trim((string) ($firewall['rule_description'] ?? '')),
    ];

    return $realm;
}

/**
 * Determine if we were invoked via CLI/cron or an HTTP cron flag plus optional realm filter.
 */
function get_cron_context(): array
{
    $context = ['is_cron' => false, 'realm' => null];
    if (PHP_SAPI === 'cli') {
        $options = getopt('', ['cron', 'realm:']);
        if (isset($options['cron'])) {
            $context['is_cron'] = true;
            $context['realm'] = $options['realm'] ?? null;
        }
    } elseif (isset($_GET['action']) && $_GET['action'] === 'cron') {
        $context['is_cron'] = true;
        $context['realm'] = $_GET['realm'] ?? null;
    }

    return $context;
}

/**
 * Guard both HTTP and CLI calls with the configured script password(s).
 */
function authenticate(array $config): void
{
    // CLI runs (cron) carry no HTTP credentials. Anyone able to execute the script
    // locally can already read the config file, so there is nothing left to guard.
    if (PHP_SAPI === 'cli') {
        return;
    }

    $realmLabel = $config['auth_realm'] ?? 'My Dynamic DNS service';
    $user = trim((string) ($config['auth_user'] ?? ''));
    if ($user === '') {
        $user = 'update'; // fallback for legacy clients
    }

    // Prefer new auth_password; fall back to legacy script_password if present.
    $passwords = (array) ($config['auth_password'] ?? $config['script_password'] ?? []);
    $passwords = array_map('strval', $passwords);
    if (empty($passwords)) {
        throw new RuntimeException('No authentication password configured. Set auth_password in config.');
    }
    $authValue = $_SERVER['HTTP_X_AUTHENTICATION'] ?? null;

    // NOTE: a PHP_AUTH_DIGEST branch used to sit here. It compared the response
    // against md5("user:password"), which is not an RFC 7616 digest response
    // (MD5(HA1:nonce:HA2)) — no real digest client could ever match it. Worse, it
    // returned early and thereby skipped every working method below. Removed.

    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        foreach ($passwords as $password) {
            if ($_SERVER['PHP_AUTH_USER'] === $user && $_SERVER['PHP_AUTH_PW'] === $password) {
                return;
            }
        }
        send_auth_headers($realmLabel);
    }

    if ($authValue !== null && in_array($authValue, $passwords, true)) {
        return;
    }

    if (isset($_SERVER['HTTP_AUTHORIZATION']) && strpos($_SERVER['HTTP_AUTHORIZATION'], 'Basic ') === 0) {
        [$basicUser, $basicPass] = array_pad(explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)), 2), 2, '');
        foreach ($passwords as $password) {
            if ($basicUser === $user && $basicPass === $password) {
                return;
            }
        }
    }

    if (isset($_GET['p']) && in_array($_GET['p'], $passwords, true)) {
        return;
    }

    send_auth_headers($realmLabel);
}

function send_auth_headers(string $realm): void
{
    header('HTTP/1.0 401 Unauthorized');
    header('WWW-Authenticate: Basic realm="' . $realm . '"');
    exit('Unauthorized');
}

function resolve_client_ip(): ?string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        return $_SERVER['REMOTE_ADDR'];
    }
    return null;
}

function parse_ip_list(?string $ip): array
{
    $result = ['ipv4' => null, 'ipv6' => null];
    if ($ip === null) {
        return $result;
    }
    $ip = trim($ip);
    if ($ip === '') {
        return $result;
    }

    $parts = array_map('trim', explode(',', $ip));
    foreach ($parts as $candidate) {
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $result['ipv4'] = $candidate;
            continue;
        }
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $result['ipv6'] = $candidate;
        }
    }

    return $result;
}

function valid_hostname(string $hostname): bool
{
    return (bool) preg_match('/^([a-z0-9](-*[a-z0-9])*)+(\.[a-z]{2,})+$/i', $hostname);
}

function split_hostname(string $hostname): array
{
    $parts = explode('.', $hostname);
    if (count($parts) < 2) {
        throw new RuntimeException('Invalid hostname format');
    }

    $domain = implode('.', array_slice($parts, -2));
    $hostnameName = implode('.', array_slice($parts, 0, -2));

    return [$hostnameName, $domain];
}

function get_zone_lookup_name(array $realmConfig, string $derivedDomain): string
{
    $zone = $realmConfig['zone_name'];
    if ($zone !== '') {
        log_debug(sprintf('Using configured zone_name "%s" instead of derived "%s".', $zone, $derivedDomain));
        return $zone;
    }
    log_debug(sprintf('No override provided, deriving zone as "%s".', $derivedDomain));
    return $derivedDomain;
}

function derive_hostname_name_from_zone(string $hostname, string $zoneName): ?string
{
    $hostname = rtrim($hostname, '.');
    $zoneName = rtrim($zoneName, '.');
    if ($zoneName === '' || strlen($zoneName) > strlen($hostname)) {
        return null;
    }
    if ($hostname === $zoneName) {
        return '@';
    }
    $suffix = '.' . $zoneName;
    if (strlen($suffix) >= strlen($hostname)) {
        return null;
    }
    if (substr($hostname, -strlen($suffix)) === $suffix) {
        return substr($hostname, 0, strlen($hostname) - strlen($suffix));
    }
    return null;
}

function log_debug(string $message): void
{
    global $debug;
    global $config;
    if (empty($debug)) {
        return;
    }

    $output = '[Hetzner DDNS] ' . $message . PHP_EOL;
    if (!empty($config['debug_log'])) {
        @file_put_contents($config['debug_log'], $output, FILE_APPEND | LOCK_EX);
        return;
    }

    error_log(trim($output));
}

function fetch_history_row(SQLite3 $db, string $hostname): ?array
{
    $stmt = $db->prepare('SELECT * FROM history WHERE hostname = :hostname');
    $stmt->bindValue(':hostname', $hostname, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row === false) {
        return null;
    }

    // Convert the empty string back to null so logic that expects a "missing" ID works consistently.
    if (isset($row['zone_id']) && $row['zone_id'] === '') {
        $row['zone_id'] = null;
    }

    return $row;
}

function should_skip_update(?array $row, array $ips): bool
{
    if (!$row) {
        return false;
    }
    if (!empty($row['needs_sync'])) {
        return false;
    }
    if ($row['ip'] !== $ips['ipv4']) {
        return false;
    }
    if ($ips['ipv6'] !== null && $row['ip6'] !== $ips['ipv6']) {
        return false;
    }

    return true;
}

/**
 * Coordinate the update attempt, record the results, and mark rows for cron retries when needed.
 */
function sync_host(SQLite3 $db, array $config, string $realmKey, array $realmConfig, string $hostname, string $zoneName, string $hostnameName, array $ips, ?array $historyRow): array
{
    if (empty($realmConfig['console_token'])) {
        $attempt = [
            'success' => false,
            'message' => 'No console_token configured for realm ' . $realmKey,
            'zone_id' => $historyRow['zone_id'] ?? null,
        ];
    } else {
        $attempt = update_via_console_api($realmConfig, $zoneName, $hostnameName, $ips, $historyRow['zone_id'] ?? null);
    }

    // Keep the optional Hetzner Cloud Firewall rule pointed at the new address.
    // A firewall failure flags the row as pending so the next client poll retries
    // both steps; the record update itself is idempotent, so re-running is safe.
    if ($attempt['success'] && !empty($realmConfig['firewall']['enabled'])) {
        $firewallResult = update_firewall($realmConfig, $ips);
        if ($firewallResult['success']) {
            $attempt['message'] .= ' | ' . $firewallResult['message'];
        } else {
            $attempt['success'] = false;
            $attempt['message'] = 'Firewall update failed: ' . $firewallResult['message'];
        }
    }

    $needsSync = !$attempt['success'];
    $retryCount = $needsSync ? min(($historyRow['retry_count'] ?? 0) + 1, $config['max_retry_attempts'] ?? 5) : 0;
    $pendingSince = $needsSync ? ($historyRow['pending_since'] ?? time()) : null;
    upsert_history(
        $db,
        $hostname,
        $realmKey,
        $ips,
        $attempt['zone_id'] ?? null,
        $needsSync,
        $retryCount,
        $attempt['message'],
        $pendingSince
    );

    return $attempt;
}

/**
 * Attempt to update the Hetzner Console API by sending rrset updates for the given host.
 */
function update_via_console_api(array $realmConfig, string $zoneName, string $hostnameName, array $ips, ?string $zoneId): array
{
    $endpoint = $realmConfig['console_endpoint'];
    $token = $realmConfig['console_token'];
    $ttl = $realmConfig['ttl'];
    log_debug(sprintf('Console API update prepared for %s in zone "%s"', $hostnameName, $zoneName));

    if (!$zoneId) {
        $lookup = fetch_console_zone_id($endpoint, $token, $zoneName);
        if (!$lookup['success']) {
            log_debug(sprintf('Console API zone lookup failed for "%s": %s', $zoneName, $lookup['message']));
            return ['success' => false, 'message' => $lookup['message'], 'zone_id' => null];
        }
        $zoneId = $lookup['zone_id'];
        log_debug(sprintf('Console API resolved zone "%s" to id %s', $zoneName, $zoneId));
    }

    // Every failure path below returns zone_id => null on purpose: a cached id that
    // no longer resolves (zone deleted and recreated) would otherwise stick forever,
    // and dropping it costs nothing but one extra lookup on the next attempt.
    $nameCandidates = build_rrset_candidates($hostnameName);
    $updates = [
        'A' => $ips['ipv4'],
        'AAAA' => $ips['ipv6'],
    ];
    $missingTypes = [];

    foreach ($updates as $type => $value) {
        if (empty($value)) {
            continue;
        }

        $rrsets = fetch_console_rrsets($endpoint, $token, $zoneId, $type);
        if (!$rrsets['success']) {
            return ['success' => false, 'message' => $rrsets['message'], 'zone_id' => null];
        }

        $chosen = choose_rrset_name($rrsets['rrsets'], $nameCandidates);
        if ($chosen === null) {
            log_debug(sprintf('No console rrset found for type %s among %s; skipping', $type, json_encode($nameCandidates)));
            $missingTypes[] = $type;
            continue;
        }

        $record = [
            'name' => $chosen,
            'type' => $type,
            'ttl' => $ttl,
            'records' => [
                ['value' => $value],
            ],
        ];
        $response = console_set_rrset($endpoint, $token, $zoneId, $record);
        if (!$response['success']) {
            return [
                'success' => false,
                'message' => $response['error'] ?? 'Console API update failed',
                'zone_id' => null,
            ];
        }
    }

    if ($missingTypes !== []) {
        return [
            'success' => false,
            'message' => sprintf(
                'No %s rrset exists for "%s" in zone "%s"; create it once in the Hetzner console',
                implode('/', $missingTypes),
                $hostnameName,
                $zoneName
            ),
            'zone_id' => null,
        ];
    }

    return [
        'success' => true,
        'message' => 'Records updated via Hetzner Console API',
        'zone_id' => $zoneId,
    ];
}

/**
 * Resolve a zone name to its console id.
 *
 * Returns the standard ['success' => bool, ...] shape so callers can tell an
 * authentication failure from a genuinely absent zone — returning null for both
 * used to surface a 401 as a misleading "zone not found".
 */
function fetch_console_zone_id(string $endpoint, string $token, string $zoneName): array
{
    $response = http_request('GET', $endpoint . '/zones?name=' . urlencode($zoneName), [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ]);

    if (!$response['success']) {
        return ['success' => false, 'message' => 'Zone lookup failed: ' . ($response['error'] ?? 'unknown error')];
    }

    $zoneId = $response['data']['zones'][0]['id'] ?? null;
    if ($zoneId === null) {
        return ['success' => false, 'message' => sprintf('Zone "%s" not found on console API', $zoneName)];
    }

    return ['success' => true, 'zone_id' => (string) $zoneId];
}

/**
 * List the rrsets of one type. Propagates API errors instead of returning an empty
 * list, which previously made an invalid token look like a missing rrset name.
 */
function fetch_console_rrsets(string $endpoint, string $token, string $zoneId, string $type): array
{
    $response = http_request('GET', $endpoint . '/zones/' . urlencode($zoneId) . '/rrsets?type=' . urlencode($type), [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ]);

    if (!$response['success']) {
        return ['success' => false, 'message' => sprintf('Rrset lookup (%s) failed: %s', $type, $response['error'] ?? 'unknown error')];
    }

    return ['success' => true, 'rrsets' => $response['data']['rrsets'] ?? []];
}

function choose_rrset_name(array $rrsets, array $candidates): ?string
{
    $available = [];
    foreach ($rrsets as $rrset) {
        if (empty($rrset['name'])) {
            continue;
        }
        $available[trim($rrset['name'], '.')] = $rrset['name'];
    }

    log_debug(sprintf('Console rrset names: %s', implode(',', array_keys($available))));
    foreach ($candidates as $candidate) {
        $key = trim($candidate, '.');
        if (isset($available[$key])) {
            return $available[$key];
        }
    }

    return null;
}

function build_rrset_candidates(string $hostnameName): array
{
    if ($hostnameName === '' || $hostnameName === '@') {
        return ['@'];
    }

    $parts = explode('.', $hostnameName);
    $candidates = [];
    for ($i = 0, $len = count($parts); $i < $len; $i++) {
        $candidates[] = implode('.', array_slice($parts, 0, $len - $i));
    }
    $candidates[] = '@';

    return array_filter(array_unique($candidates));
}

/**
 * Wrapper for curl calls that returns standardized success/error details.
 */
function http_request(string $method, string $url, array $headers = [], $body = null): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($body !== null) {
        $payload = is_string($body) ? $body : json_encode($body);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $responseBody = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = null;
    if ($responseBody !== false) {
        $decoded = json_decode($responseBody, true);
        $data = $decoded === null ? null : $decoded;
    }

    $success = $responseBody !== false && $status >= 200 && $status < 300;
    $message = $error;
    if (!$message && !$success && is_array($data)) {
        $message = $data['error']['message'] ?? $data['error'] ?? null;
    }

    // A 2xx that is not JSON is not a success: a retired or misrouted endpoint
    // answering 200 with an HTML page would otherwise be read as an empty result
    // and reported as "zone not found" instead of naming the real problem.
    if ($success && !is_array($data)) {
        $success = false;
        $message = sprintf('expected JSON but got %s (HTTP %d)', $responseBody === '' ? 'an empty body' : 'a non-JSON response', $status);
    }

    $bodySnippet = $responseBody !== false ? (strlen($responseBody) > 400 ? substr($responseBody, 0, 400) . '...' : $responseBody) : 'no body';
    log_debug(sprintf('HTTP %s %s -> %d (%s); body=%s; error=%s', $method, $url, $status, $success ? 'success' : 'failure', $bodySnippet, $message ?? 'none'));

    return [
        'success' => $success,
        'http_code' => $status,
        'body' => $responseBody,
        'data' => $data,
        'error' => $message,
    ];
}

function send_notification(array $config, string $realm, string $hostname, array $ips, array $result): void
{
    $defaults = [
        'enabled' => false,
        'method' => 'php',
        'from' => 'no-reply@localhost',
        'recipients' => [],
        'success_recipients' => [],
        'failure_recipients' => [],
        'send_on_success' => false,
        'send_on_failure' => true,
        'smtp' => [],
    ];
    $notifications = array_merge($defaults, $config);
    if (empty($notifications['enabled'])) {
        return;
    }

    $isSuccess = !empty($result['success']);
    if ($isSuccess && empty($notifications['send_on_success'])) {
        return;
    }
    if (!$isSuccess && empty($notifications['send_on_failure'])) {
        return;
    }

    $specific = $isSuccess ? $notifications['success_recipients'] : $notifications['failure_recipients'];
    $recipients = array_unique(array_filter($specific ?: $notifications['recipients']));
    if (empty($recipients)) {
        return;
    }

    $subject = sprintf('[Hetzner DDNS] %s %s', $isSuccess ? 'success' : 'failure', $hostname);
    $body = sprintf(
        "Realm: %s\nHostname: %s\nIPv4: %s\nIPv6: %s\nResult: %s\nMessage: %s\n",
        $realm,
        $hostname,
        $ips['ipv4'] ?? '-',
        $ips['ipv6'] ?? '-',
        $isSuccess ? 'success' : 'failure',
        $result['message'] ?? 'n/a'
    );

    $sent = false;
    if ($notifications['method'] === 'smtp') {
        $sent = send_email_smtp($notifications['smtp'], $notifications['from'], $recipients, $subject, $body);
    } else {
        $sent = send_email_php($notifications['from'], $recipients, $subject, $body);
    }

    log_debug(sprintf(
        'Notification (%s) for %s via %s was %s; recipients=%s',
        $isSuccess ? 'success' : 'failure',
        $hostname,
        $notifications['method'],
        $sent ? 'sent' : 'failed',
        implode(', ', $recipients)
    ));
}

function send_email_php(string $from, array $recipients, string $subject, string $body): bool
{
    $to = implode(', ', $recipients);
    $headers = sprintf("From: %s\r\n", $from);
    return mail($to, $subject, normalize_email_body($body), $headers);
}

function send_email_smtp(array $smtp, string $from, array $recipients, string $subject, string $body): bool
{
    $host = $smtp['host'] ?? '';
    $port = $smtp['port'] ?? 25;
    $user = $smtp['username'] ?? '';
    $pass = $smtp['password'] ?? '';
    $security = strtolower($smtp['security'] ?? 'tls');
    $useSsl = $security === 'ssl';
    $protocol = $useSsl ? 'ssl://' : '';

    $fp = stream_socket_client($protocol . $host . ':' . $port, $errno, $errstr, 30);
    if (!$fp) {
        log_debug(sprintf('SMTP connection failed: %s (%s)', $errstr, $errno));
        return false;
    }

    stream_set_timeout($fp, 5);
    $greeting = smtp_read_response($fp);
    if ($greeting['code'] !== 220) {
        log_debug(sprintf('SMTP greeting failed: %s', implode(', ', $greeting['lines'])));
        fclose($fp);
        return false;
    }

    $ehlo = smtp_command($fp, "EHLO " . gethostname() . "\r\n");
    if ($ehlo['code'] !== 250) {
        log_debug(sprintf('SMTP EHLO failed: %s', implode(', ', $ehlo['lines'])));
        fclose($fp);
        return false;
    }

    $supportsStartTls = collect_smtp_keywords($ehlo['lines'], 'STARTTLS');
    if ($security === 'tls') {
        if (!$supportsStartTls) {
            log_debug('SMTP server does not advertise STARTTLS, cannot use tls security');
            fclose($fp);
            return false;
        }

        $resp = smtp_command($fp, "STARTTLS\r\n");
        if ($resp['code'] !== 220) {
            log_debug(sprintf('SMTP STARTTLS failed: %s', implode(', ', $resp['lines'])));
            fclose($fp);
            return false;
        }

        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            log_debug('SMTP STARTTLS: failed to enable crypto');
            fclose($fp);
            return false;
        }

        $ehlo = smtp_command($fp, "EHLO " . gethostname() . "\r\n");
        if ($ehlo['code'] !== 250) {
            log_debug(sprintf('SMTP EHLO after STARTTLS failed: %s', implode(', ', $ehlo['lines'])));
            fclose($fp);
            return false;
        }
    }

    if ($user !== '' && $pass !== '') {
        $resp = smtp_command($fp, "AUTH LOGIN\r\n");
        if ($resp['code'] !== 334) {
            log_debug(sprintf('SMTP AUTH LOGIN refused: %s', implode(', ', $resp['lines'])));
            fclose($fp);
            return false;
        }
        $resp = smtp_command($fp, base64_encode($user) . "\r\n");
        if ($resp['code'] !== 334) {
            log_debug('SMTP AUTH username rejected');
            fclose($fp);
            return false;
        }
        $resp = smtp_command($fp, base64_encode($pass) . "\r\n");
        if ($resp['code'] !== 235) {
            log_debug('SMTP AUTH password rejected');
            fclose($fp);
            return false;
        }
    }

    $resp = smtp_command($fp, "MAIL FROM:<$from>\r\n");
    if ($resp['code'] !== 250) {
        log_debug(sprintf('SMTP MAIL FROM failed: %s', implode(', ', $resp['lines'])));
        fclose($fp);
        return false;
    }

    foreach ($recipients as $recipient) {
        $rcptResp = smtp_command($fp, "RCPT TO:<$recipient>\r\n");
        if (!in_array($rcptResp['code'], [250, 251], true)) {
            log_debug(sprintf('SMTP RCPT TO %s failed: %s', $recipient, implode(', ', $rcptResp['lines'])));
            fclose($fp);
            return false;
        }
    }

    $dataResp = smtp_command($fp, "DATA\r\n");
    if ($dataResp['code'] !== 354) {
        log_debug(sprintf('SMTP DATA command rejected: %s', implode(', ', $dataResp['lines'])));
        fclose($fp);
        return false;
    }

    fwrite($fp, "Subject: $subject\r\n");
    fwrite($fp, "From: $from\r\n");
    fwrite($fp, "To: " . implode(', ', $recipients) . "\r\n");
    fwrite($fp, "\r\n" . normalize_email_body($body) . "\r\n.\r\n");
    $final = smtp_read_response($fp);
    if ($final['code'] !== 250) {
        log_debug(sprintf('SMTP DATA body rejected: %s', implode(', ', $final['lines'])));
        fclose($fp);
        return false;
    }

    smtp_command($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}

function smtp_command($fp, string $command): array
{
    fputs($fp, $command);
    return smtp_read_response($fp);
}

function smtp_read_response($fp): array
{
    $lines = [];
    while (($line = fgets($fp)) !== false) {
        $trim = trim($line, "\r\n");
        if ($trim === '') {
            continue;
        }
        $lines[] = $trim;
        if (strlen($trim) >= 4 && $trim[3] === ' ') {
            return ['code' => (int) substr($trim, 0, 3), 'lines' => $lines];
        }
    }
    return ['code' => 0, 'lines' => $lines];
}

function collect_smtp_keywords(array $lines, string $keyword): bool
{
    $keyword = strtoupper($keyword);
    foreach ($lines as $line) {
        if (stripos($line, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

function normalize_email_body(string $body): string
{
    $body = str_replace("\r\n", "\n", $body);
    $chunks = explode("\n", $body);
    return implode("\r\n", $chunks);
}

function console_set_rrset(string $endpoint, string $token, string $zoneId, array $record): array
{
    if (empty($record['records'])) {
        return [
            'success' => false,
            'http_code' => 400,
            'body' => null,
            'data' => null,
            'error' => 'Console rrset payload missing records',
        ];
    }
    $rrset = [
        'name' => $record['name'],
        'type' => $record['type'],
        'ttl' => $record['ttl'],
        'records' => $record['records'],
    ];
    $payload = [
        'ttl' => $rrset['ttl'],
        'records' => $rrset['records'],
    ];
    log_debug(sprintf('Setting console rrset %s/%s -> %s', $record['name'], $record['type'], json_encode($rrset['records'])));
    $name = $rrset['name'] === '@' ? '_' : $rrset['name'];
    return http_request('POST', $endpoint . '/zones/' . $zoneId . '/rrsets/' . urlencode($name) . '/' . urlencode($rrset['type']) . '/actions/set_records', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ], $payload);
}

/**
 * Point an existing Hetzner Cloud Firewall rule at the current dynamic IP.
 *
 * Uses the same console endpoint/token as the rrset updates. The rule must
 * already exist; this only rewrites its source_ips. Because the set_rules
 * action replaces the ENTIRE rule set, every untouched rule is sent back
 * unchanged alongside the modified one.
 */
function update_firewall(array $realmConfig, array $ips): array
{
    $fw = $realmConfig['firewall'];
    $endpoint = $realmConfig['console_endpoint'];
    $token = (string) ($realmConfig['console_token'] ?? '');

    if ($token === '') {
        return ['success' => false, 'message' => 'console_token required for firewall updates'];
    }
    if ($fw['port'] === '') {
        return ['success' => false, 'message' => 'firewall.port is not configured'];
    }

    $firewall = fetch_firewall($endpoint, $token, $fw);
    if (!$firewall['success']) {
        return $firewall;
    }

    $sourceIps = [$ips['ipv4'] . '/32'];
    if (!empty($ips['ipv6'])) {
        $sourceIps[] = $ips['ipv6'] . '/128';
    }

    $rules = $firewall['rules'];

    // Collect matches first. Without a rule_description the filter is just
    // direction+protocol+port, which can legitimately hit several rules (e.g. a
    // static office allowlist on the same port). Rewriting those would silently
    // hand someone else's access to this client, and set_rules makes it immediate,
    // so refuse instead of guessing.
    $matches = [];
    foreach ($rules as $index => $rule) {
        if (($rule['direction'] ?? '') !== 'in') {
            continue;
        }
        if (strtolower((string) ($rule['protocol'] ?? '')) !== $fw['protocol']) {
            continue;
        }
        if ((string) ($rule['port'] ?? '') !== $fw['port']) {
            continue;
        }
        if ($fw['rule_description'] !== '' && (string) ($rule['description'] ?? '') !== $fw['rule_description']) {
            continue;
        }
        $matches[] = $index;
    }

    if (count($matches) > 1 && $fw['rule_description'] === '') {
        return ['success' => false, 'message' => sprintf(
            'Firewall rule match is ambiguous: %d rules match in/%s port %s. Set firewall.rule_description to disambiguate.',
            count($matches),
            $fw['protocol'],
            $fw['port']
        )];
    }

    $matched = 0;
    foreach ($matches as $index) {
        $rules[$index]['source_ips'] = $sourceIps;
        $matched++;
    }

    if ($matched === 0) {
        return ['success' => false, 'message' => sprintf(
            'No firewall rule matched in/%s port %s%s',
            $fw['protocol'],
            $fw['port'],
            $fw['rule_description'] !== '' ? ' description "' . $fw['rule_description'] . '"' : ''
        )];
    }

    log_debug(sprintf(
        'Setting firewall %s source_ips to %s on %d rule(s)',
        $firewall['id'],
        implode(',', $sourceIps),
        $matched
    ));
    $response = http_request('POST', $endpoint . '/firewalls/' . urlencode($firewall['id']) . '/actions/set_rules', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ], ['rules' => normalize_firewall_rules($rules)]);

    if (!$response['success']) {
        return ['success' => false, 'message' => $response['error'] ?? 'Firewall set_rules failed'];
    }

    return ['success' => true, 'message' => sprintf('Firewall rule updated (%d rule(s) -> %s)', $matched, implode(', ', $sourceIps))];
}

/**
 * Resolve the configured firewall (by id or name) to its id plus rule list.
 */
function fetch_firewall(string $endpoint, string $token, array $fw): array
{
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];

    if (!empty($fw['firewall_id'])) {
        $response = http_request('GET', $endpoint . '/firewalls/' . urlencode((string) $fw['firewall_id']), $headers);
        $firewall = $response['data']['firewall'] ?? null;
    } elseif ($fw['firewall_name'] !== '') {
        $response = http_request('GET', $endpoint . '/firewalls?name=' . urlencode($fw['firewall_name']), $headers);
        $firewall = $response['data']['firewalls'][0] ?? null;
    } else {
        return ['success' => false, 'message' => 'firewall_id or firewall_name is required'];
    }

    if (!$response['success'] || !$firewall) {
        return ['success' => false, 'message' => $response['error'] ?? 'Firewall not found'];
    }

    return ['success' => true, 'id' => (string) $firewall['id'], 'rules' => $firewall['rules'] ?? []];
}

/**
 * Reduce fetched rules to the fields set_rules accepts and strip null values
 * (e.g. icmp rules carry no port), so the rule set round-trips unmodified.
 */
function normalize_firewall_rules(array $rules): array
{
    $allowed = ['direction', 'protocol', 'port', 'source_ips', 'destination_ips', 'description'];
    $normalized = [];
    foreach ($rules as $rule) {
        $entry = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $rule) && $rule[$key] !== null) {
                $entry[$key] = $rule[$key];
            }
        }
        $normalized[] = $entry;
    }

    return $normalized;
}

/**
 * Persist the latest IP state plus retry metadata so the next attempt can resume.
 *
 * Columns are named explicitly, so databases created before the legacy DNS API was
 * removed keep their now-unused recordA_id/recordAAAA_id columns without migration.
 */
function upsert_history(SQLite3 $db, string $hostname, string $realm, array $ips, ?string $zoneId, bool $needsSync, int $retryCount, ?string $lastError, ?int $pendingSince): void
{
    $stmt = $db->prepare('INSERT INTO history (hostname, realm, ip, ip6, zone_id, timestamp, needs_sync, retry_count, last_error, pending_since)
        VALUES (:hostname, :realm, :ip, :ip6, :zone_id, :timestamp, :needs_sync, :retry_count, :last_error, :pending_since)
        ON CONFLICT(hostname) DO UPDATE SET
        realm = excluded.realm,
        ip = excluded.ip,
        ip6 = excluded.ip6,
        zone_id = excluded.zone_id,
        timestamp = excluded.timestamp,
        needs_sync = excluded.needs_sync,
        retry_count = excluded.retry_count,
        last_error = excluded.last_error,
        pending_since = excluded.pending_since');

    $stmt->bindValue(':hostname', $hostname, SQLITE3_TEXT);
    $stmt->bindValue(':realm', $realm, SQLITE3_TEXT);
    $stmt->bindValue(':ip', $ips['ipv4'], SQLITE3_TEXT);
    $stmt->bindValue(':ip6', $ips['ipv6'], SQLITE3_TEXT);
    $stmt->bindValue(':zone_id', $zoneId ?? '', SQLITE3_TEXT);
    $stmt->bindValue(':timestamp', time(), SQLITE3_INTEGER);
    $stmt->bindValue(':needs_sync', $needsSync ? 1 : 0, SQLITE3_INTEGER);
    $stmt->bindValue(':retry_count', $retryCount, SQLITE3_INTEGER);
    $stmt->bindValue(':last_error', $lastError, SQLITE3_TEXT);
    $stmt->bindValue(':pending_since', $pendingSince, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Re-run pending updates stored in the history table; useful for cron jobs.
 */
function process_pending_updates(SQLite3 $db, array $config, ?string $realmFilter): array
{
    $query = 'SELECT * FROM history WHERE needs_sync = 1';
    if ($realmFilter !== null) {
        $query .= ' AND realm = :realm';
    }
    $stmt = $db->prepare($query);
    if ($realmFilter !== null) {
        $stmt->bindValue(':realm', $realmFilter, SQLITE3_TEXT);
    }
    $result = $stmt->execute();

    $summary = ['total' => 0, 'success' => 0, 'failed' => 0, 'details' => []];
    // Retry loop: keep trying pending hostnames until they either succeed or exhaust the retry limits.
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $summary['total']++;
        $realmKey = $row['realm'] ?: get_default_realm_key($config);

        if (!isset($config['realms'][$realmKey])) {
            upsert_history($db, $row['hostname'], $realmKey, ['ipv4' => $row['ip'], 'ipv6' => $row['ip6']], $row['zone_id'], true, ($row['retry_count'] ?? 0), 'Realm removed', $row['pending_since']);
            $summary['details'][$row['hostname']] = ['status' => 'failed', 'message' => 'Realm not configured'];
            $summary['failed']++;
            continue;
        }

        $realmConfig = get_realm_config($config, $realmKey);
        [$hostnameName, $domain] = split_hostname($row['hostname']);
        $zoneLookupName = get_zone_lookup_name($realmConfig, $domain);
        $overriddenHostnameName = derive_hostname_name_from_zone($row['hostname'], $zoneLookupName);
        if ($overriddenHostnameName !== null) {
            $hostnameName = $overriddenHostnameName === '' ? '@' : $overriddenHostnameName;
        }
        $ips = ['ipv4' => $row['ip'], 'ipv6' => $row['ip6']];
        // Deliberately NOT named $result: that variable holds the SQLite3Result the
        // while condition above iterates. Overwriting it killed the loop after the
        // first pending host with "fetchArray() on array".
        $syncResult = sync_host($db, $config, $realmKey, $realmConfig, $row['hostname'], $zoneLookupName, $hostnameName, $ips, $row);
        if ($syncResult['success']) {
            $summary['success']++;
            $summary['details'][$row['hostname']] = ['status' => 'success'];
        } else {
            $summary['failed']++;
            $summary['details'][$row['hostname']] = ['status' => 'failed', 'message' => $syncResult['message']];
        }
    }

    return $summary;
}

/**
 * Build a cron-friendly summary of how many hosts succeeded or still need retries.
 */
function render_cron_summary(array $summary): string
{
    $lines = [sprintf('processed: %d, success: %d, failed: %d', $summary['total'], $summary['success'], $summary['failed'])];
    // Append a line per hostname to clarify which API handled the retry or why it still fails.
    foreach ($summary['details'] as $hostname => $detail) {
        $line = " - $hostname: {$detail['status']}";
        if (!empty($detail['message'])) {
            $line .= ' (' . $detail['message'] . ')';
        }
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

class DDnsDB extends SQLite3
{
    public function __construct(array $config)
    {
        // Fail loudly instead of warning: a corrupt or unwritable database would
        // otherwise emit a cascade of warnings and let the request limp on with a
        // broken handle — and a failed INSERT would still answer "good". The
        // top-level exception handler turns any of that into a clean 500.
        // (Not switched back afterwards: enableExceptions(false) is deprecated as
        // of PHP 8.3, and the notice would itself start output prematurely.)
        $this->enableExceptions(true);

        // Without the fallback a config missing this key would yield null, and
        // SQLite3::open(null) silently opens a *temporary* database: every request
        // would start empty and needs_sync would never persist. Matches the default
        // used by hetzner_dyndns_listhosts.php.
        $this->open($config['history_db'] ?? (__DIR__ . '/hetzner_dyndns.sqlite3'));
        $this->busyTimeout(5000);
        $this->exec('PRAGMA foreign_keys = ON');
        $this->exec('PRAGMA journal_mode = WAL');
        $this->exec("PRAGMA synchronous = NORMAL");
        $this->exec("PRAGMA auto_vacuum = FULL");
        $this->exec("PRAGMA case_sensitive_like = OFF");
        $this->exec("PRAGMA encoding = 'UTF-8'");
        $this->exec("PRAGMA temp_store = MEMORY");
        $this->exec("PRAGMA cache_size = -2000");
        $this->exec("PRAGMA prepare_v2 = ON");
        $this->ensure_schema();
    }

    private function ensure_schema(): void
    {
        $this->exec('CREATE TABLE IF NOT EXISTS history (
            hostname TEXT PRIMARY KEY,
            realm TEXT NOT NULL DEFAULT \'\',
            ip TEXT,
            ip6 TEXT,
            zone_id TEXT,
            timestamp INTEGER,
            needs_sync INTEGER DEFAULT 0,
            retry_count INTEGER DEFAULT 0,
            last_error TEXT,
            pending_since INTEGER
        )');

        $existing = [];
        $result = $this->query('PRAGMA table_info(history)');
        while ($column = $result->fetchArray(SQLITE3_ASSOC)) {
            $existing[$column['name']] = true;
        }

        $required = [
            'realm' => "TEXT NOT NULL DEFAULT ''",
            'needs_sync' => 'INTEGER DEFAULT 0',
            'retry_count' => 'INTEGER DEFAULT 0',
            'last_error' => 'TEXT',
            'pending_since' => 'INTEGER',
        ];

        foreach ($required as $name => $definition) {
            if (!isset($existing[$name])) {
                $this->exec(sprintf('ALTER TABLE history ADD COLUMN %s %s', $name, $definition));
            }
        }
    }
}
