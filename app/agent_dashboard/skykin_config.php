<?php
/**
 * SkyKin shared runtime config (migrate-friendly).
 *
 * Defaults derive from the current request / FusionPBX session — never from
 * a hard-coded LAN IP or client1.skykin.local.
 *
 * Optional overrides (merged in order):
 *   1. /etc/skykin/config.php          (server-wide, preferred on cloud)
 *   2. __DIR__/skykin_local_config.php (per-install; keep out of git)
 *   3. Environment variables SKYKIN_*
 *
 * Override files must return an array, e.g.:
 *   return ['recordings_api_base' => 'http://pbx.example.com:8001'];
 */

if (!function_exists('skykin_http_host')) {

function skykin_http_host(): string {
	$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
	return preg_replace('/:\d+$/', '', $host);
}

/** Public IP FreeSWITCH puts in WebRTC SDP for agent legs. */
function skykin_rtp_advertise_ip(): string {
	$env = getenv('EXTERNAL_RTP_IP');
	if ($env !== false && trim($env) !== '') {
		return trim($env);
	}
	return '196.189.236.126';
}

function skykin_is_https(): bool {
	return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
}

function skykin_default_domain(): string {
	// Logged-in tenant first. SKYKIN_DOMAIN is only a fallback for IP logins
	// that have no session domain yet — it must not pin every agent to client1.
	if (!empty($_SESSION['domain_name'])) {
		return (string)$_SESSION['domain_name'];
	}
	if (!empty($_SESSION['user_context'])) {
		return (string)$_SESSION['user_context'];
	}
	$env = getenv('SKYKIN_DOMAIN');
	if ($env !== false && trim($env) !== '') {
		return trim($env);
	}
	return skykin_http_host();
}

/**
 * Domain from request, falling back to session / host.
 */
function skykin_domain_param($from_request = null): string {
	$d = trim((string)($from_request ?? ''));
	if ($d !== '') {
		return $d;
	}
	return skykin_default_domain();
}

/** Public URL for the SkyKin favicon used across all Sky Connect / FusionPBX pages. */
function skykin_favicon_url(): string {
	return '/favicon.png';
}

/** HTML link tags for the SkyKin favicon. */
function skykin_favicon_tag(): string {
	$url = htmlspecialchars(skykin_favicon_url(), ENT_QUOTES, 'UTF-8');
	return '<link rel="icon" type="image/png" href="' . $url . '">' . "\n"
		. '<link rel="shortcut icon" type="image/png" href="' . $url . '">' . "\n";
}

/**
 * Digits-only phone (for CRM / caller-ID matching).
 */
function skykin_phone_digits(string $phone): string {
	return preg_replace('/\D+/', '', $phone);
}

/**
 * Last 9 digits of an Ethiopian mobile (handles 09…, 2519…, +2519…).
 */
function skykin_phone_tail(string $phone, int $len = 9): string {
	$d = skykin_phone_digits($phone);
	if ($d === '') {
		return '';
	}
	if (strlen($d) >= 12 && str_starts_with($d, '251')) {
		$d = substr($d, 3);
	}
	if (strlen($d) >= 10 && $d[0] === '0') {
		$d = substr($d, 1);
	}
	return strlen($d) >= $len ? substr($d, -$len) : $d;
}

/** Store mobiles as 09XXXXXXXX when possible. */
function skykin_normalize_phone_storage(string $phone): string {
	$d = skykin_phone_digits($phone);
	if ($d === '') {
		return trim($phone);
	}
	if (strlen($d) >= 12 && str_starts_with($d, '251')) {
		return '0' . substr($d, 3);
	}
	if (strlen($d) === 9 && $d[0] === '9') {
		return '0' . $d;
	}
	if ($d[0] !== '0' && strlen($d) >= 9) {
		return '0' . substr($d, -9);
	}
	return $d;
}

/**
 * Find a CRM contact by phone using tail-9 matching (09… vs +251…).
 */
function skykin_crm_ensure_contacts(PDO $db): void {
	$isSqlite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
	if ($isSqlite) {
		$db->exec("CREATE TABLE IF NOT EXISTS skykin_contacts (
			contact_id    INTEGER PRIMARY KEY AUTOINCREMENT,
			phone         TEXT NOT NULL UNIQUE,
			alt_phone     TEXT,
			full_name     TEXT NOT NULL,
			email         TEXT,
			company       TEXT,
			language      TEXT DEFAULT 'English',
			account_type  TEXT DEFAULT 'Customer',
			notes         TEXT,
			created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
		)");
	} else {
		$db->exec("CREATE TABLE IF NOT EXISTS skykin_contacts (
			contact_id    SERIAL PRIMARY KEY,
			phone         TEXT NOT NULL UNIQUE,
			alt_phone     TEXT,
			full_name     TEXT NOT NULL,
			email         TEXT,
			company       TEXT,
			language      TEXT DEFAULT 'English',
			account_type  TEXT DEFAULT 'Customer',
			notes         TEXT,
			created_at    TIMESTAMP DEFAULT NOW(),
			updated_at    TIMESTAMP DEFAULT NOW()
		)");
	}
}

function skykin_crm_find_contact(PDO $db, string $phone): ?array {
	$tail = skykin_phone_tail($phone);
	if ($tail === '') {
		return null;
	}
	try {
		skykin_crm_ensure_contacts($db);
	} catch (Throwable $e) {
		return null;
	}
	$isSqlite = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
	if (!$isSqlite) {
		$s = $db->prepare(
			"SELECT * FROM skykin_contacts WHERE
				RIGHT(regexp_replace(COALESCE(phone,''), '[^0-9]', '', 'g'), 9) = :tail
				OR RIGHT(regexp_replace(COALESCE(alt_phone,''), '[^0-9]', '', 'g'), 9) = :tail
			ORDER BY contact_id DESC LIMIT 1"
		);
		$s->execute([':tail' => $tail]);
		$row = $s->fetch(PDO::FETCH_ASSOC);
		if ($row) {
			return $row;
		}
	}
	$clean = preg_replace('/^(\+251|00251|0)/', '', $phone);
	$s = $db->prepare(
		"SELECT * FROM skykin_contacts
			WHERE phone LIKE :q OR alt_phone LIKE :q
			   OR phone LIKE :c OR alt_phone LIKE :c
			ORDER BY contact_id DESC LIMIT 50"
	);
	$s->execute([':q' => '%' . $phone . '%', ':c' => '%' . $clean . '%']);
	foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
		foreach (['phone', 'alt_phone'] as $col) {
			if (skykin_phone_tail((string)($row[$col] ?? '')) === $tail) {
				return $row;
			}
		}
	}
	return null;
}

/**
 * Timezone used for every date/epoch boundary in the dashboards.
 *
 * PHP defaults to UTC when date.timezone is unset, while Postgres renders CDR
 * timestamps in the server zone. That mismatch silently drops calls from
 * "today" windows, so resolve the real server zone instead.
 */
function skykin_timezone(): string {
	static $tz = null;
	if ($tz !== null) {
		return $tz;
	}

	$candidates = [];
	$cfg_file_tz = null;
	foreach ([
		'/etc/skykin/config.php',
		__DIR__ . '/skykin_local_config.php',
	] as $override) {
		if (is_file($override)) {
			$extra = include $override;
			if (is_array($extra) && !empty($extra['timezone'])) {
				$cfg_file_tz = (string)$extra['timezone'];
			}
		}
	}
	$candidates[] = $cfg_file_tz;
	$candidates[] = getenv('SKYKIN_TZ') ?: null;
	if (is_file('/etc/timezone')) {
		$sys_tz = trim((string)file_get_contents('/etc/timezone'));
		if ($sys_tz !== '' && strcasecmp($sys_tz, 'UTC') !== 0 && strcasecmp($sys_tz, 'Etc/UTC') !== 0) {
			$candidates[] = $sys_tz;
		}
	}
	if (is_link('/etc/localtime')) {
		$link = (string)readlink('/etc/localtime');
		if (preg_match('#zoneinfo/(.+)$#', $link, $m)
			&& strcasecmp($m[1], 'UTC') !== 0
			&& strcasecmp($m[1], 'Etc/UTC') !== 0) {
			$candidates[] = $m[1];
		}
	}

	$candidates[] = 'Africa/Addis_Ababa';

	foreach ($candidates as $candidate) {
		if (!$candidate) {
			continue;
		}
		try {
			new DateTimeZone($candidate);
		} catch (Exception $e) {
			continue;
		}
		$tz = $candidate;
		return $tz;
	}

	$tz = date_default_timezone_get() ?: 'UTC';
	return $tz;
}

/**
 * SQL fragment that matches CDRs belonging to an agent extension.
 *
 * Outbound legs store the extension as caller_id_number. Inbound queue legs
 * store the DID or 8000 as destination and the answering agent in cc_agent,
 * so a caller/destination-only filter silently drops every inbound.
 */
function skykin_cdr_agent_sql(string $ext_param = ':e'): string {
	return '('
		. 'caller_id_number = ' . $ext_param
		. ' OR destination_number = ' . $ext_param
		. ' OR caller_destination = ' . $ext_param
		. ' OR (cc_agent IN ('
		. 'SELECT call_center_agent_uuid::text FROM v_call_center_agents'
		. ' WHERE agent_id = ' . $ext_param
		. " OR agent_contact LIKE '%/' || " . $ext_param . " || '@%'"
		. ") AND destination_number ~ '^[+0-9]{3,}$')"
		. ')';
}

function skykin_config(): array {
	static $cfg = null;
	if ($cfg !== null) {
		return $cfg;
	}

	$host = skykin_http_host();
	$cfg = [
		'domain'              => skykin_default_domain(),
		'http_host'           => $host,
		'sip_server'          => $host,
		'ahununu_url'         => 'https://ahununu.com/',
		// Empty = use FusionPBX recordings only (recommended for cloud).
		'recordings_api_base' => '',
		// Empty = do not open a Socket.IO connection.
		'socket_io_url'       => '',
		'sms_enabled'         => true,
		'wss_path'            => '/wss/',
		'seed_demo_data'      => false,
		'timezone'            => skykin_timezone(),
	];

	foreach ([
		'/etc/skykin/config.php',
		__DIR__ . '/skykin_local_config.php',
	] as $override) {
		if (is_file($override)) {
			$extra = include $override;
			if (is_array($extra)) {
				$cfg = array_merge($cfg, $extra);
			}
		}
	}

	$map = [
		'SKYKIN_AHUNUNU_URL'     => 'ahununu_url',
		'SKYKIN_RECORDINGS_API'  => 'recordings_api_base',
		'SKYKIN_SOCKET_IO_URL'   => 'socket_io_url',
		'SKYKIN_SIP_SERVER'      => 'sip_server',
		'SKYKIN_WSS_PATH'        => 'wss_path',
		'SKYKIN_TZ'              => 'timezone',
	];
	foreach ($map as $env => $key) {
		$val = getenv($env);
		if ($val !== false && $val !== '') {
			$cfg[$key] = $val;
		}
	}
	$seed = getenv('SKYKIN_SEED_DEMO_DATA');
	if ($seed !== false) {
		$cfg['seed_demo_data'] = in_array(strtolower((string)$seed), ['1', 'true', 'yes'], true);
	}

	return $cfg;
}

/**
 * FusionPBX PostgreSQL connection — reads from resources/config.php.
 *
 * FIX (2026-08-07): Previously read from /etc/fusionpbx/config.conf which
 * only exists on the Linux VM, not on Windows/WSL dev machines. This caused
 * a silent fallback to SQLite, meaning the Agent Dashboard wrote tickets to
 * a local SQLite file while the Department Ticket Portal read from the real
 * PostgreSQL database — tickets were invisible across apps.
 *
 * Now reads credentials from resources/config.php (the same file the ticket
 * portal uses) and throws a visible RuntimeException if the connection fails,
 * rather than silently returning null (which triggered the SQLite fallback).
 */
function skykin_pdo_fusionpbx(): PDO {
	static $db = null;
	if ($db !== null) return $db;

	$h  = getenv('DB_HOST') ?: 'db';
	$p  = getenv('DB_PORT') ?: '5432';
	$n  = getenv('DB_NAME') ?: 'fusionpbx';
	$u  = getenv('DB_USER') ?: 'fusionpbx';
	$pw = getenv('DB_PASSWORD') !== false ? (string)getenv('DB_PASSWORD') : '';

	$conf = '/etc/fusionpbx/config.conf';
	if (is_file($conf)) {
		foreach (file($conf) as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') {
				continue;
			}
			if (strpos($line, '=') === false) {
				continue;
			}
			[$k, $v] = array_map('trim', explode('=', $line, 2));
			if ($k === 'database.0.host') {
				$h = $v;
			}
			if ($k === 'database.0.port') {
				$p = $v;
			}
			if ($k === 'database.0.name') {
				$n = $v;
			}
			if ($k === 'database.0.username') {
				$u = $v;
			}
			if ($k === 'database.0.password') {
				$pw = $v;
			}
		}
	} else {
		$fpbxConfig = dirname(__DIR__, 2) . '/resources/config.php';
		if (is_file($fpbxConfig)) {
			@include $fpbxConfig;
			if (!empty($db_host)) {
				$h = $db_host;
			}
			if (!empty($db_port)) {
				$p = $db_port;
			}
			if (!empty($db_name)) {
				$n = $db_name;
			}
			if (!empty($db_username)) {
				$u = $db_username;
			}
			if (isset($db_password)) {
				$pw = $db_password;
			}
		}
	}

	// ── Single direct connection attempt ─────────────────────────────────────
	try {
		$db = new PDO(
			"pgsql:host={$h};port={$p};dbname={$n};connect_timeout=5",
			$u, $pw,
			[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
		return $db;
	} catch (Exception $e) {
		// LOUD failure — do NOT silently fall back to a different database.
		// A hidden SQLite fallback caused tickets to be written to the wrong
		// database and disappear from the ticket portal entirely.
		throw new RuntimeException(
			"[SkyKin DB] Cannot connect to PostgreSQL at {$h}:{$p}/{$n}. " .
			"Check resources/config.php credentials. Original error: " .
			$e->getMessage()
		);
	}
}

/**
 * Event Socket settings.
 *
 * FreeSWITCH's own event_socket.conf.xml is authoritative when FreeSWITCH runs
 * on this host, so a fresh install needs no hand-written config. .env and
 * ESL_* environment variables override it for remote/dev setups.
 *
 * A stale ESL_HOST pointing at a dev LAN address silently breaks every agent
 * status change, so an unreachable host falls back to the local socket.
 */
function skykin_esl_settings(): array {
	static $s = null;
	if ($s !== null) {
		return $s;
	}

	$s = ['host' => '127.0.0.1', 'port' => 8021, 'password' => 'ClueCon'];

	$conf = '/etc/freeswitch/autoload_configs/event_socket.conf.xml';
	if (is_file($conf) && is_readable($conf)) {
		$xml = (string)file_get_contents($conf);
		if (preg_match('/name="listen-ip"\s+value="([^"]*)"/', $xml, $m) && $m[1] !== '') {
			$s['host'] = ($m[1] === '::' || $m[1] === '0.0.0.0') ? '127.0.0.1' : $m[1];
		}
		if (preg_match('/name="listen-port"\s+value="([^"]*)"/', $xml, $m) && $m[1] !== '') {
			$s['port'] = (int)$m[1];
		}
		if (preg_match('/name="password"\s+value="([^"]*)"/', $xml, $m) && $m[1] !== '') {
			$s['password'] = $m[1];
		}
	}

	foreach ([
		__DIR__ . '/../../.env',
		__DIR__ . '/../.env',
		__DIR__ . '/.env',
	] as $envPath) {
		if (!is_file($envPath)) {
			continue;
		}
		foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ln) {
			$ln = trim($ln);
			if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) {
				continue;
			}
			[$k, $v] = explode('=', $ln, 2);
			$k = trim($k);
			$v = trim($v, " \t\n\r\0\x0B\"'");
			if ($v === '') {
				continue;
			}
			if ($k === 'ESL_HOST') {
				$s['host'] = $v;
			} elseif ($k === 'ESL_PORT') {
				$s['port'] = (int)$v;
			} elseif ($k === 'ESL_PASSWORD') {
				$s['password'] = $v;
			}
		}
		break;
	}

	foreach (['ESL_HOST' => 'host', 'ESL_PORT' => 'port', 'ESL_PASSWORD' => 'password'] as $env => $key) {
		$v = getenv($env);
		if ($v !== false && $v !== '') {
			$s[$key] = ($key === 'port') ? (int)$v : $v;
		}
	}

	return $s;
}

/**
 * Connected event_socket, or null. $error receives the reason on failure.
 */
function skykin_esl(?string &$error = null) {
	$error = '';
	if (!class_exists('config')) {
		require_once __DIR__ . '/../../resources/classes/config.php';
	}
	if (!class_exists('event_socket')) {
		require_once __DIR__ . '/../../resources/classes/event_socket.php';
	}

	$s = skykin_esl_settings();
	$targets = [[$s['host'], $s['port']]];
	if ($s['host'] !== '127.0.0.1') {
		$targets[] = ['127.0.0.1', $s['port']];
	}

	foreach ($targets as [$host, $port]) {
		try {
			$esl = new event_socket();
			if ($esl->connect($host, $port, $s['password'])) {
				return $esl;
			}
			$error = 'ESL connect refused by ' . $host . ':' . $port;
		} catch (Throwable $ex) {
			$error = $ex->getMessage();
		}
	}
	return null;
}

/**
 * Run a FreeSWITCH API command and return its raw output ('' on failure).
 *
 * Prefer ESL over shelling out to fs_cli: in the Docker deployment FreeSWITCH
 * lives in its own container, so the binary simply does not exist next to PHP
 * and every shell_exec("fs_cli ...") returns null. That turns silently into
 * "no agents / no registrations / no active calls" on the supervisor board and
 * makes call monitoring fail as if the agent were idle. ESL reaches FreeSWITCH
 * over the network, so it works both containerised and on a single host, with
 * fs_cli kept only as a fallback for installs where ESL is locked down.
 *
 * The connection is reused across calls: a dashboard refresh issues several
 * commands and each connect/auth round trip costs a socket and ~ms of latency.
 */
function skykin_fs_api(string $command): string {
	static $esl = null;
	static $tried = false;

	$command = trim($command);
	if ($command === '') {
		return '';
	}

	if (!$tried) {
		$tried = true;
		$esl = skykin_esl($ignored_error);
	}

	if ($esl) {
		try {
			$res = $esl->request('api ' . $command);
			if (is_array($res)) {
				// event_socket returns the body under '$' and headers alongside it.
				return (string)($res['$'] ?? implode(' | ', array_filter($res, 'is_scalar')));
			}
			return (string)$res;
		} catch (Throwable $ignored) {
			// Fall through to fs_cli below; a dropped socket should not be fatal.
			$esl = null;
		}
	}

	static $fs_cli = null;
	if ($fs_cli === null) {
		$fs_cli = '';
		if (function_exists('shell_exec')) {
			foreach (['/usr/bin/fs_cli', '/usr/local/bin/fs_cli', '/usr/local/freeswitch/bin/fs_cli'] as $p) {
				if (is_executable($p)) {
					$fs_cli = $p;
					break;
				}
			}
		}
	}
	if ($fs_cli !== '') {
		return (string)shell_exec($fs_cli . ' -x ' . escapeshellarg($command) . ' 2>/dev/null');
	}

	return '';
}

/**
 * Make sure mod_callcenter knows an agent, creating it if necessary.
 *
 * mod_callcenter's agent list is built from callcenter.conf.xml when FreeSWITCH
 * starts, so an agent added to FusionPBX afterwards does not exist as far as
 * FreeSWITCH is concerned and "callcenter_config agent set status" answers
 * "-ERR Agent not found!". mod_callcenter can be provisioned at runtime, so
 * create the agent, its contact and its queue membership on demand instead of
 * requiring a restart.
 *
 * Every command is idempotent: "already exists" replies are expected and ignored.
 * $agent is the name mod_callcenter knows the agent by, which the dashboards set
 * to the FusionPBX call_center_agent_uuid.
 */
function skykin_cc_ensure_agent(string $agent, string $extension, string $domain, array $queues = []): bool {
	if ($agent === '' || $extension === '' || $domain === '') {
		return false;
	}

	skykin_fs_api('callcenter_config agent add ' . $agent . ' callback');
	// Keep this contact identical to the live ringing originate. Do not put
	// api_hangup_hook here: a space in that value breaks the agent ring.
	// Decline is attached on the inbound DID via cc_export_vars instead.
	$rtp_ip = skykin_rtp_advertise_ip();
	skykin_fs_api('callcenter_config agent set contact ' . $agent
		. " '{ignore_early_media=true,bridge_early_media=false,originate_timeout=45}[leg_timeout=30,media_webrtc=true,rtp_secure_media=optional,rtp_advertise_ip="
		. $rtp_ip . ",include_external_ip=true]user/"
		. $extension . '@' . $domain . "'");
	skykin_fs_api('callcenter_config agent set max_no_answer ' . $agent . ' 999');
	skykin_fs_api('callcenter_config agent set wrap_up_time ' . $agent . ' 0');
	skykin_fs_api('callcenter_config agent set ready_time ' . $agent . ' 0');
	// Stop Decline from re-ringing the same agent instantly (reject_delay 0
	// plus max-wait-time 0 made the inbound call never end).
	skykin_fs_api('callcenter_config agent set reject_delay_time ' . $agent . ' 15');
	skykin_fs_api('callcenter_config agent set busy_delay_time ' . $agent . ' 15');

	if (!$queues) {
		$queues = [getenv('FS_QUEUE_EXT') ?: '8000'];
	}
	foreach ($queues as $queue) {
		$queue = trim((string)$queue);
		if ($queue === '') {
			continue;
		}
		if (strpos($queue, '@') === false) {
			$queue .= '@' . $domain;
		}
		// New FusionPBX domains must not require .env / container recreate.
		skykin_fs_api('callcenter_config queue load ' . $queue);
		skykin_fs_api('callcenter_config tier add ' . $queue . ' ' . $agent . ' 1 1');
	}

	// Confirm rather than trusting the replies: "add" reports an error both when the
	// agent already existed and when the name was rejected.
	$list = skykin_fs_api('callcenter_config agent list');
	return $list !== '' && strpos($list, $agent) !== false;
}

/**
 * Extensions currently registered to FreeSWITCH, as [extension => true].
 *
 * "show registrations" reads the core database, which is unavailable whenever
 * FreeSWITCH runs with -nosql and returns "-ERR SQL disabled". Sofia keeps its
 * own registration state in memory, so fall back to the profile listing: that
 * keeps the dashboards' online/offline column honest either way.
 */
function skykin_fs_registrations(string $domain = '', string $profile = 'internal'): array {
	$registered = [];

	$json = json_decode(skykin_fs_api('show registrations as json'), true);
	foreach ((is_array($json) ? ($json['rows'] ?? []) : []) as $row) {
		$user  = trim((string)($row['reg_user'] ?? $row['user'] ?? ''));
		$realm = trim((string)($row['realm'] ?? ''));
		if ($user !== '' && ($domain === '' || $realm === '' || strcasecmp($realm, $domain) === 0)) {
			$registered[$user] = true;
		}
	}
	if ($registered) {
		return $registered;
	}

	// Profile listing prints stanzas of "User:\t<ext>@<realm>" then "Status:\t...".
	$out = skykin_fs_api('sofia status profile ' . $profile . ' reg');
	$pending = null;
	foreach (preg_split("/\r\n|\n|\r/", $out) ?: [] as $line) {
		$line = trim($line);
		if (stripos($line, 'User:') === 0) {
			$who = trim(substr($line, 5));
			$pending = null;
			if (strpos($who, '@') !== false) {
				[$user, $realm] = explode('@', $who, 2);
				$user  = trim($user);
				$realm = trim($realm);
				if ($user !== '' && ($domain === '' || strcasecmp($realm, $domain) === 0)) {
					$pending = $user;
				}
			}
		} elseif ($pending !== null && stripos($line, 'Status:') === 0) {
			if (stripos($line, 'Registered') !== false) {
				$registered[$pending] = true;
			}
			$pending = null;
		}
	}

	return $registered;
}

/**
 * Whether a FreeSWITCH channel belongs to this dashboard tenant.
 * Public inbound is always context=public; classify 755/756 vs 757-759 by dest.
 */
function skykin_channel_for_domain(array $row, string $domain, string $dest_digits = ''): bool {
	$domain = strtolower(trim($domain));
	$want_ahununu = ($domain === 'ahununu');
	$ctx = strtolower((string)($row['context'] ?? ''));
	$presence = strtolower((string)($row['presence_id'] ?? '') . ' ' . (string)($row['name'] ?? ''));
	if ($dest_digits === '') {
		$dest_digits = preg_replace('/\D+/', '', (string)($row['dest'] ?? '')) ?? '';
	}
	$is_ahununu_did = (bool)preg_match('/11113875[789]$/', $dest_digits)
		|| (bool)preg_match('/11619803[5-9]$/', $dest_digits);
	$is_client1_did = (bool)preg_match('/11113875[56]$/', $dest_digits);
	if ($is_ahununu_did) {
		return $want_ahununu;
	}
	if ($is_client1_did) {
		return !$want_ahununu;
	}
	if ($ctx === 'ahununu' || strpos($presence, '@ahununu') !== false) {
		return $want_ahununu;
	}
	if (strpos($ctx, 'client1') !== false || strpos($presence, '@client1') !== false) {
		return !$want_ahununu;
	}
	if ($ctx === 'public') {
		return false;
	}
	return $ctx === $domain || strpos($presence, '@' . $domain) !== false;
}

/**
 * Format a wait duration for the queue waiting-caller lists.
 */
function skykin_cc_wait_fmt(int $seconds): string {
	$seconds = max(0, $seconds);
	if ($seconds < 60) {
		return $seconds . 's';
	}
	return ((int)floor($seconds / 60)) . 'm ' . ($seconds % 60) . 's';
}

/**
 * Live callers waiting in mod_callcenter, plus inbound still ringing.
 *
 * Waiting customers live in FreeSWITCH, not Postgres. This is view-only:
 * the dashboards list who is in line; longest-idle-agent still assigns the
 * next call. $queue_exts is the queue numbers (e.g. 8000); 8000 is always
 * included so a missing FusionPBX queue row does not hide the live line.
 */
function skykin_cc_waiting_callers(string $domain, array $queue_exts = []): array {
	$now = time();
	$seen = [];
	$out = [];
	$exts = [];
	foreach ($queue_exts as $e) {
		$e = trim((string)$e);
		if ($e !== '' && preg_match('/^\d{3,8}$/', $e)) {
			$exts[$e] = true;
		}
	}
	$exts['8000'] = true;

	$cell = static function (array $cols, array $header, string $key, string $alt = ''): string {
		$i = $header[$key] ?? ($alt !== '' ? ($header[$alt] ?? null) : null);
		if ($i === null || !isset($cols[$i])) {
			return '';
		}
		return trim((string)$cols[$i]);
	};

	foreach (array_keys($exts) as $qext) {
		$raw = skykin_fs_api('callcenter_config queue list members ' . $qext . '@' . $domain);
		$header = null;
		foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
			$line = trim($line);
			if ($line === '' || strncmp($line, '+OK', 3) === 0 || strncmp($line, '-ERR', 4) === 0) {
				continue;
			}
			$cols = explode('|', $line);
			if ($header === null) {
				$joined = strtolower(implode('|', $cols));
				if (strpos($joined, 'cid_number') !== false || strpos($joined, 'session_uuid') !== false) {
					$header = [];
					foreach ($cols as $i => $name) {
						$header[strtolower(trim((string)$name))] = $i;
					}
					continue;
				}
				$header = [
					'queue' => 0, 'name' => 1, 'session_uuid' => 2, 'cid_number' => 3,
					'cid_name' => 4, 'system_epoch' => 5, 'joined_epoch' => 6,
					'rejoined_epoch' => 7, 'bridge_epoch' => 8, 'abandoned_epoch' => 9,
					'base_score' => 10, 'skill_score' => 11, 'serving_agent' => 12,
					'serving_system' => 13, 'state' => 14,
				];
			}
			$state = strtolower($cell($cols, $header, 'state'));
			if ($state === '' || in_array($state, ['abandoned', 'answered'], true)) {
				continue;
			}
			$uuid = $cell($cols, $header, 'session_uuid', 'uuid');
			$joined = (int)$cell($cols, $header, 'joined_epoch', 'system_epoch');
			$wait = max(0, $now - ($joined > 0 ? $joined : $now));
			$cid = $cell($cols, $header, 'cid_number');
			if ($uuid !== '') {
				$seen[$uuid] = true;
			}
			$out[] = [
				'number' => $cid !== '' ? $cid : 'Unknown',
				'name' => $cell($cols, $header, 'cid_name'),
				'queue' => $qext,
				'state' => ($state === 'trying') ? 'Offering' : 'Waiting',
				'wait_seconds' => $wait,
				'wait_fmt' => skykin_cc_wait_fmt($wait),
			];
		}
	}

	// DID hunt rings 101/102 without entering queue 8000. Surface those too.
	$json = json_decode(skykin_fs_api('show channels as json'), true);
	foreach ((is_array($json) ? ($json['rows'] ?? []) : []) as $row) {
		if (!is_array($row)) {
			continue;
		}
		$uuid = (string)($row['uuid'] ?? '');
		if ($uuid !== '' && isset($seen[$uuid])) {
			continue;
		}
		if (strtolower((string)($row['direction'] ?? '')) !== 'inbound') {
			continue;
		}
		$callstate = strtoupper((string)($row['callstate'] ?? ''));
		// RINGING/EARLY = still offering an agent. ACTIVE is already in the
		// call. Lua/WebRTC often leaves b_uuid empty, so treating ACTIVE as
		// waiting showed the live call as "Connecting".
		if (!in_array($callstate, ['RINGING', 'EARLY'], true)) {
			continue;
		}
		$cid = trim((string)($row['cid_num'] ?? $row['cid_number'] ?? ''));
		$dest = trim((string)($row['dest'] ?? ''));
		$cid_digits = preg_replace('/\D+/', '', $cid);
		$dest_digits = preg_replace('/\D+/', '', $dest);
		if (preg_match('/^1\d{2}$/', (string)$cid_digits) && preg_match('/^1\d{2}$/', (string)$dest_digits)) {
			continue;
		}
		// Public inbound is context=public for every DID. Classify by dest so
		// 755/756 never appear on ahununu and 757-759 never appear on client1.
		if (!skykin_channel_for_domain($row, $domain, (string)$dest_digits)) {
			continue;
		}
		$created = (int)($row['created_epoch'] ?? 0);
		$wait = max(0, $now - ($created > 0 ? $created : $now));
		$out[] = [
			'number' => $cid !== '' ? $cid : 'Unknown',
			'name' => (string)($row['cid_name'] ?? ''),
			'queue' => $dest !== '' ? $dest : 'inbound',
			'state' => 'Ringing',
			'wait_seconds' => $wait,
			'wait_fmt' => skykin_cc_wait_fmt($wait),
		];
	}

	usort($out, static function ($a, $b) {
		return ((int)$b['wait_seconds'] <=> (int)$a['wait_seconds']);
	});
	return $out;
}

/**
 * Absolute path of a recording, or '' when it cannot be found.
 *
 * $dir is the CDR's record_path when known; the remaining candidates cover
 * softphone uploads, the legacy flat root and the FusionPBX archive tree. Shared
 * with play_recording.php so the Recordings tab never offers a file the streamer
 * would answer 404 for. Nothing outside the recordings root is ever returned.
 */
function skykin_recording_path(string $file, string $domain, string $dir = ''): string {
	$root = '/var/lib/freeswitch/recordings';
	$file = basename($file);
	if ($file === '' || !preg_match('/^[\w.\-]+$/', $file)) {
		return '';
	}

	$candidates = [];
	if ($dir !== '') {
		$candidates[] = rtrim($dir, '/') . '/' . $file;
	}
	$candidates[] = $root . '/' . $domain . '/agent/' . $file;
	$candidates[] = $root . '/' . $domain . '/' . $file;
	$candidates[] = $root . '/' . $file;

	foreach ($candidates as $candidate) {
		$real = realpath($candidate);
		if ($real !== false && is_file($real) && strpos($real, $root . '/') === 0) {
			return $real;
		}
	}

	// Archive recordings are nested by year/month/day, so search as a last resort.
	$archive = $root . '/' . $domain . '/archive';
	if (is_dir($archive)) {
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($archive, FilesystemIterator::SKIP_DOTS)
			);
			foreach ($it as $entry) {
				if ($entry->isFile() && $entry->getFilename() === $file) {
					return (string)$entry->getRealPath();
				}
			}
		} catch (Throwable $ignored) {
			// Unreadable subdirectory: treat as not found.
		}
	}

	return '';
}

/**
 * True when a recording actually contains audio a browser can play.
 *
 * FreeSWITCH opens the WAV as soon as record_session runs, so a call whose media
 * never carried any frames leaves a bare 44-byte header behind. Those files load
 * fine but stay silent with a zero duration, which reads as "the recording does
 * not play", so the dashboards leave them out of the list.
 */
function skykin_recording_playable(string $path): bool {
	if ($path === '' || !is_file($path) || !is_readable($path)) {
		return false;
	}
	$size = (int)filesize($path);
	if (strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) !== 'wav') {
		// Browser uploads are compressed; size is the only cheap signal.
		return $size > 1024;
	}

	$fh = @fopen($path, 'rb');
	if (!$fh) {
		return false;
	}
	$head = (string)fread($fh, 4096);
	fclose($fh);
	if (substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
		return false;
	}
	$pos = strpos($head, 'data');
	if ($pos === false) {
		return false;
	}
	$channels = (int)(@unpack('v', substr($head, 22, 2))[1] ?? 0);
	$rate     = (int)(@unpack('V', substr($head, 24, 4))[1] ?? 0);
	$declared = (int)(@unpack('V', substr($head, $pos + 4, 4))[1] ?? 0);
	if ($channels < 1 || $rate < 1) {
		return false;
	}
	// The data length is written when the file is closed, so a recording still in
	// progress (or interrupted) reports 0 while samples are already on disk.
	$bytes = max($declared, $size - ($pos + 8));

	return ($bytes / ($rate * $channels * 2)) >= 0.5;
}

/**
 * Stamp record_path/record_name on CDR rows from archive/${uuid}.wav files.
 */
function skykin_link_archive_recordings(PDO $db, string $domain, int $ts, int $te): void {
	$root = '/var/lib/freeswitch/recordings/' . $domain . '/archive';
	if (!is_dir($root)) {
		return;
	}
	$stmt = null;
	for ($day = strtotime('midnight', $ts); $day !== false && $day <= $te; $day = strtotime('+1 day', $day)) {
		$dir = $root . '/' . date('Y/M/d', $day);
		foreach (glob($dir . '/*.wav') ?: [] as $path) {
			$uuid = pathinfo($path, PATHINFO_FILENAME);
			if (!preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $uuid)) {
				continue;
			}
			if ($stmt === null) {
				$stmt = $db->prepare(
					"UPDATE v_xml_cdr SET record_path = :path, record_name = :name
					 WHERE xml_cdr_uuid = :uuid
					   AND (record_name IS NULL OR record_name = '')"
				);
			}
			$stmt->execute([':path' => $dir, ':name' => basename($path), ':uuid' => $uuid]);
		}
	}
}

/**
 * Where dashboard diagnostics are written.
 */
function skykin_log_path(string $name): string {
	$dir = '/var/log/skykin';
	if (is_dir($dir) && is_writable($dir)) {
		return $dir . '/' . $name;
	}
	return sys_get_temp_dir() . '/skykin_' . $name;
}

function skykin_ensure_settings_table(PDO $db): void {
	$db->exec("CREATE TABLE IF NOT EXISTS skykin_settings (
		setting_key VARCHAR(64) PRIMARY KEY,
		setting_value TEXT NOT NULL,
		updated_at TIMESTAMP DEFAULT NOW(),
		updated_by VARCHAR(255)
	)");
}

function skykin_setting_get(string $key, string $default = ''): string {
	static $cache = [];
	if (array_key_exists($key, $cache)) {
		return $cache[$key];
	}
	try {
		$db = skykin_pdo_fusionpbx();
		skykin_ensure_settings_table($db);
		$s = $db->prepare('SELECT setting_value FROM skykin_settings WHERE setting_key = :k');
		$s->execute([':k' => $key]);
		$v = $s->fetchColumn();
		$cache[$key] = ($v === false) ? $default : (string) $v;
	} catch (Throwable $e) {
		$cache[$key] = $default;
	}
	return $cache[$key];
}

function skykin_setting_set(string $key, string $value, string $by = ''): void {
	$db = skykin_pdo_fusionpbx();
	skykin_ensure_settings_table($db);
	$s = $db->prepare(
		"INSERT INTO skykin_settings (setting_key, setting_value, updated_at, updated_by)
		 VALUES (:k, :v, NOW(), :by)
		 ON CONFLICT (setting_key) DO UPDATE
		 SET setting_value = EXCLUDED.setting_value,
		     updated_at = NOW(),
		     updated_by = EXCLUDED.updated_by"
	);
	$s->execute([':k' => $key, ':v' => $value, ':by' => $by]);
}

/** Minutes of no mouse/keyboard/call before logout. 0 = disabled. */
function skykin_idle_timeout_minutes(): int {
	$n = (int) skykin_setting_get('session_idle_minutes', '30');
	if ($n < 0) {
		$n = 0;
	}
	if ($n > 1440) {
		$n = 1440;
	}
	return $n;
}

function skykin_session_clear_auth(): void {
	$_SESSION['authorized'] = false;
	unset($_SESSION['user_uuid'], $_SESSION['authorized'], $_SESSION['user']);
}

function skykin_session_enforce_idle(): void {
	if (empty($_SESSION['authorized']) && empty($_SESSION['user_uuid'])) {
		return;
	}
	$minutes = skykin_idle_timeout_minutes();
	if ($minutes <= 0) {
		return;
	}
	$last = (int) ($_SESSION['session']['last_activity'] ?? 0);
	if ($last <= 0) {
		$_SESSION['session']['last_activity'] = time();
		return;
	}
	if ((time() - $last) > ($minutes * 60)) {
		skykin_session_clear_auth();
	}
}

function skykin_session_touch(): void {
	if (empty($_SESSION['authorized']) && empty($_SESSION['user_uuid'])) {
		return;
	}
	$_SESSION['session']['last_activity'] = time();
}

/**
 * Emit window.SKYKIN = {...} for dashboard JS.
 */
function skykin_js_bootstrap(): string {
	$c = skykin_config();
	$payload = [
		'domain'              => $c['domain'],
		'httpHost'            => $c['http_host'],
		'sipServer'           => $c['sip_server'],
		'ahununuUrl'          => $c['ahununu_url'],
		'recordingsApiBase'   => rtrim((string)$c['recordings_api_base'], '/'),
		'socketIoUrl'         => rtrim((string)$c['socket_io_url'], '/'),
		'smsEnabled'          => !empty($c['sms_enabled']),
		'wssPath'             => $c['wss_path'],
		'idleTimeoutMinutes'  => skykin_idle_timeout_minutes(),
		'idlePingUrl'         => 'session_ping.php',
	];
	return 'window.SKYKIN=' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . ';';
}

/**
 * Flatten FusionPBX $_SESSION['groups'] to lowercase group name list.
 */
function skykin_user_groups(): array {
	$raw = $_SESSION['groups'] ?? [];
	$out = [];
	array_walk_recursive($raw, function ($val) use (&$out) {
		if (!is_string($val)) {
			return;
		}
		foreach (array_map('trim', explode(',', $val)) as $g) {
			if ($g !== '') {
				$out[] = strtolower($g);
			}
		}
	});
	return array_values(array_unique($out));
}

function skykin_user_in_groups(array $allowed): bool {
	$allowed = array_map('strtolower', $allowed);
	return !empty(array_intersect($allowed, skykin_user_groups()));
}

/**
 * Require an authenticated FusionPBX session.
 * @param bool $json  API-style JSON 401 instead of redirect
 */
function skykin_require_login(bool $json = false): void {
	skykin_session_enforce_idle();
	if (!empty($_SESSION['user_uuid']) && !empty($_SESSION['authorized'])) {
		if (!$json) {
			skykin_session_touch();
		}
		return;
	}
	if ($json) {
		header('Content-Type: application/json');
		http_response_code(401);
		echo json_encode(['ok' => false, 'error' => 'Session expired', 'login' => '/']);
		exit;
	}
	$path = $_SERVER['REQUEST_URI'] ?? '/';
	header('Location: /?path=' . urlencode($path));
	exit;
}

/**
 * Require the current user to be in one of the allowed groups.
 */
function skykin_require_groups(array $allowed, bool $json = false): void {
	skykin_require_login($json);
	if (skykin_user_in_groups($allowed)) {
		return;
	}
	if ($json) {
		header('Content-Type: application/json');
		http_response_code(403);
		echo json_encode(['ok' => false, 'error' => 'Access denied']);
		exit;
	}
	http_response_code(403);
	echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Access Denied – Sky Connect</title>' . skykin_favicon_tag() . '
<style>body{font-family:Segoe UI,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f2f5;margin:0}
.box{background:#fff;padding:40px 48px;border-radius:14px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)}
h2{color:#c62828;margin:0 0 10px}p{color:#666;font-size:14px}a{color:#0047AB}</style></head>
<body><div class="box"><h2>Access Denied</h2><p>You do not have permission to open this page.</p>
<p><a href="/app/agent_dashboard/index.php">Back to Agent Dashboard</a></p>
<p style="margin-top:24px;font-size:11px;color:#aaa">Sky Connect | Powered by SkyKin Technology</p></div></body></html>';
	exit;
}

date_default_timezone_set(skykin_timezone());

/**
 * Blacklist: Postgres (dashboard list) + FreeSWITCH file/hash (inbound drop).
 * Line format: domain|digits|display|reason|agent|unix
 * Hash keys are domain-scoped (`domain~digits`); lists are not shared across domains.
 */
if (is_file(__DIR__ . '/skykin_bl_sync.php')) {
	require_once __DIR__ . '/skykin_bl_sync.php';
}

function skykin_blacklist_path(): string {
	return '/etc/freeswitch/scripts/skykin_blacklist.txt';
}

function skykin_blacklist_digits(string $number): string {
	$d = preg_replace('/\D+/', '', $number) ?? '';
	if (strlen($d) >= 12 && substr($d, 0, 3) === '251') {
		$d = substr($d, 3);
	}
	if (strlen($d) === 10 && $d !== '' && $d[0] === '0') {
		$d = substr($d, 1);
	}
	return $d;
}

function skykin_blacklist_parse(string $text): array {
	$rows = [];
	foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') {
			continue;
		}
		$p = explode('|', $line);
		$digits = skykin_blacklist_digits((string)($p[1] ?? $p[0] ?? ''));
		if ($digits === '') {
			continue;
		}
		$rows[] = [
			'domain'  => $p[0] ?? '*',
			'digits'  => $digits,
			'display' => $p[2] ?? $digits,
			'reason'  => $p[3] ?? '',
			'agent'   => $p[4] ?? '',
			'ts'      => (int)($p[5] ?? 0),
		];
	}
	return $rows;
}

function skykin_blacklist_buf(array $rows): string {
	$buf = "# domain|digits|display|reason|agent|unix\n";
	foreach ($rows as $r) {
		$digits = skykin_blacklist_digits((string)($r['digits'] ?? ''));
		if ($digits === '') {
			continue;
		}
		$buf .= implode('|', [
			str_replace('|', '', (string)($r['domain'] ?? '*')),
			$digits,
			str_replace('|', '', (string)($r['display'] ?? $digits)),
			str_replace('|', '', (string)($r['reason'] ?? '')),
			str_replace('|', '', (string)($r['agent'] ?? '')),
			(string)((int)($r['ts'] ?? time())),
		]) . "\n";
	}
	return $buf;
}

function skykin_blacklist_db(): ?PDO {
	if (!function_exists('skykin_pdo_fusionpbx')) {
		return null;
	}
	try {
		$db = skykin_pdo_fusionpbx();
		if (function_exists('skykin_bl_ensure_table')) {
			skykin_bl_ensure_table($db);
		} else {
			$db->exec("CREATE TABLE IF NOT EXISTS skykin_blacklist (
				digits text NOT NULL,
				domain_name text NOT NULL DEFAULT '*',
				display text,
				reason text,
				agent text,
				ts bigint
			)");
			try {
				$db->exec('ALTER TABLE skykin_blacklist DROP CONSTRAINT IF EXISTS skykin_blacklist_pkey');
				$db->exec('ALTER TABLE skykin_blacklist ADD PRIMARY KEY (digits, domain_name)');
			} catch (Throwable $e) {
			}
		}
		return $db;
	} catch (Throwable $e) {
		return null;
	}
}

function skykin_blacklist_load(): array {
	$by = [];
	$add = static function (array $rows) use (&$by): void {
		foreach ($rows as $r) {
			$d = skykin_blacklist_digits((string)($r['digits'] ?? ''));
			if ($d === '') {
				continue;
			}
			$r['digits'] = $d;
			$dom = (string)($r['domain'] ?? $r['domain_name'] ?? '*');
			$r['domain'] = $dom;
			$by[$dom . '|' . $d] = $r;
		}
	};
	$db = skykin_blacklist_db();
	if ($db) {
		try {
			$got = [];
			foreach ($db->query('SELECT digits, domain_name, display, reason, agent, ts FROM skykin_blacklist ORDER BY ts DESC') as $r) {
				$got[] = [
					'domain'  => (string)$r['domain_name'],
					'digits'  => (string)$r['digits'],
					'display' => (string)($r['display'] ?: $r['digits']),
					'reason'  => (string)$r['reason'],
					'agent'   => (string)$r['agent'],
					'ts'      => (int)$r['ts'],
				];
			}
			$add($got);
		} catch (Throwable $e) {
		}
	}
	foreach ([
		'/var/lib/freeswitch/recordings/skykin_blacklist.txt',
		'/etc/freeswitch/scripts/skykin_blacklist.txt',
	] as $path) {
		if (is_readable($path)) {
			$add(skykin_blacklist_parse((string)@file_get_contents($path)));
		}
	}
	if (function_exists('skykin_fs_api')) {
		$add(skykin_blacklist_parse((string)skykin_fs_api('system cat /etc/freeswitch/scripts/skykin_blacklist.txt')));
		$add(skykin_blacklist_parse((string)skykin_fs_api('system cat /var/lib/freeswitch/recordings/skykin_blacklist.txt')));
	}
	return array_values($by);
}

function skykin_blacklist_save(array $rows): bool {
	$norm = [];
	$seen = [];
	foreach ($rows as $r) {
		$digits = skykin_blacklist_digits((string)($r['digits'] ?? ''));
		$dom = (string)($r['domain'] ?? $r['domain_name'] ?? '*');
		if ($digits === '' || strlen($digits) < 7 || isset($seen[$dom . '|' . $digits])) {
			continue;
		}
		$seen[$dom . '|' . $digits] = true;
		$norm[] = [
			'domain'  => $dom,
			'digits'  => $digits,
			'display' => (string)($r['display'] ?? $digits),
			'reason'  => (string)($r['reason'] ?? ''),
			'agent'   => (string)($r['agent'] ?? ''),
			'ts'      => (int)($r['ts'] ?? time()),
		];
	}
	$db_ok = false;
	$db = skykin_blacklist_db();
	if ($db) {
		try {
			$db->beginTransaction();
			$db->exec('DELETE FROM skykin_blacklist');
			$st = $db->prepare('INSERT INTO skykin_blacklist (digits, domain_name, display, reason, agent, ts) VALUES (?,?,?,?,?,?)');
			foreach ($norm as $r) {
				$st->execute([$r['digits'], $r['domain'], $r['display'], $r['reason'], $r['agent'], $r['ts']]);
			}
			$db->commit();
			$db_ok = true;
		} catch (Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
		}
	}
	$buf = skykin_blacklist_buf($norm);
	foreach ([
		'/etc/freeswitch/scripts/skykin_blacklist.txt',
		'/var/lib/freeswitch/recordings/skykin_blacklist.txt',
	] as $path) {
		@file_put_contents($path, $buf, LOCK_EX);
		@chmod($path, 0666);
	}
	$file_ok = false;
	if (function_exists('skykin_fs_api')) {
		$b64 = base64_encode($buf);
		foreach ([
			'/etc/freeswitch/scripts/skykin_blacklist.txt',
			'/var/lib/freeswitch/recordings/skykin_blacklist.txt',
		] as $path) {
			skykin_fs_api('system sh -c "printf %s ' . $b64 . ' | base64 -d > ' . $path . ' && chmod 666 ' . $path . '"');
		}
		$got = (string)skykin_fs_api('system cat /etc/freeswitch/scripts/skykin_blacklist.txt');
		foreach ($norm as $r) {
			if (strpos($got, $r['digits']) !== false) {
				$file_ok = true;
				break;
			}
		}
		if (!$norm) {
			$file_ok = true;
		}
		foreach ($norm as $r) {
			$d = $r['digits'];
			$dom = str_replace(['/', ' ', '|', '~'], '', (string)$r['domain']);
			if (function_exists('skykin_bl_hash_scope')) {
				skykin_bl_hash_scope($dom, $d, true);
			} else {
				skykin_fs_api('hash delete/skykin_bl/' . $d);
				if ($dom !== '') {
					skykin_fs_api('hash insert/skykin_bl/' . $dom . '~' . $d . '/1');
					if (strlen($d) >= 9) {
						skykin_fs_api('hash insert/skykin_bl/' . $dom . '~' . substr($d, -9) . '/1');
					}
				}
			}
		}
	}
	return $db_ok || $file_ok || ($norm && skykin_blacklist_match($norm[0]['digits'], (string)($norm[0]['domain'] ?? '')));
}

function skykin_blacklist_match(string $number, string $domain = ''): bool {
	$want = skykin_blacklist_digits($number);
	$domain = trim($domain);
	if ($want === '' || strlen($want) < 7 || $domain === '') {
		return false;
	}
	foreach (skykin_blacklist_load() as $r) {
		$row_dom = (string)($r['domain'] ?? $r['domain_name'] ?? '');
		if (strcasecmp($row_dom, $domain) !== 0) {
			continue;
		}
		$have = skykin_blacklist_digits((string)($r['digits'] ?? ''));
		if ($have === '') {
			continue;
		}
		$n = min(strlen($want), strlen($have), 12);
		if ($n >= 7 && substr($want, -$n) === substr($have, -$n)) {
			return true;
		}
	}
	return false;
}

} // function_exists guard
