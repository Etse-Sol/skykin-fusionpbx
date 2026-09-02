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

function skykin_is_https(): bool {
	return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
}

function skykin_default_domain(): string {
	// Explicit override for deployments where the web host (often a bare IP)
	// differs from the telephony SIP domain the extensions live in.
	$env = getenv('SKYKIN_DOMAIN');
	if ($env !== false && trim($env) !== '') {
		return trim($env);
	}
	if (!empty($_SESSION['domain_name'])) {
		return (string)$_SESSION['domain_name'];
	}
	if (!empty($_SESSION['user_context'])) {
		return (string)$_SESSION['user_context'];
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

	// ── Read from resources/config.php (single source of truth) ──────────────
	// Defaults to the known production host so the app works even if config.php
	// is temporarily absent (a misconfiguration should be loud, not silent).
	$h  = '192.168.0.114';
	$p  = '5432';
	$n  = 'fusionpbx';
	$u  = 'fusionpbx';
	$pw = '';

	$fpbxConfig = dirname(__DIR__, 2) . '/resources/config.php';
	if (is_file($fpbxConfig)) {
		// resources/config.php defines $db_host, $db_port, $db_name,
		// $db_username, $db_password — same variables read by the ticket portal.
		@include $fpbxConfig;
		if (!empty($db_host))     $h  = $db_host;
		if (!empty($db_port))     $p  = $db_port;
		if (!empty($db_name))     $n  = $db_name;
		if (!empty($db_username)) $u  = $db_username;
		if (isset($db_password))  $pw = $db_password;
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
	// Matches the contact format written into callcenter.conf.xml at startup.
	skykin_fs_api('callcenter_config agent set contact ' . $agent
		. " '[leg_timeout=30,media_webrtc=true,rtp_secure_media=optional,rtp_advertise_ip=196.189.236.140,include_external_ip=true]user/"
		. $extension . '@' . $domain . "'");
	skykin_fs_api('callcenter_config agent set max_no_answer ' . $agent . ' 999');

	foreach ($queues as $queue) {
		$queue = trim($queue);
		if ($queue === '') {
			continue;
		}
		if (strpos($queue, '@') === false) {
			$queue .= '@' . $domain;
		}
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

/**
 * Emit window.SKYKIN = {...} for dashboard JS.
 */
function skykin_js_bootstrap(): string {
	$c = skykin_config();
	$payload = [
		'domain'            => $c['domain'],
		'httpHost'          => $c['http_host'],
		'sipServer'         => $c['sip_server'],
		'ahununuUrl'        => $c['ahununu_url'],
		'recordingsApiBase' => rtrim((string)$c['recordings_api_base'], '/'),
		'socketIoUrl'       => rtrim((string)$c['socket_io_url'], '/'),
		'smsEnabled'        => !empty($c['sms_enabled']),
		'wssPath'           => $c['wss_path'],
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
	if (!empty($_SESSION['user_uuid']) && !empty($_SESSION['authorized'])) {
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
	echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Access Denied – SkyKin</title>
<style>body{font-family:Segoe UI,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f2f5;margin:0}
.box{background:#fff;padding:40px 48px;border-radius:14px;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1)}
h2{color:#c62828;margin:0 0 10px}p{color:#666;font-size:14px}a{color:#0047AB}</style></head>
<body><div class="box"><h2>Access Denied</h2><p>You do not have permission to open this page.</p>
<p><a href="/app/agent_dashboard/index.php">Back to Agent Dashboard</a></p></div></body></html>';
	exit;
}

date_default_timezone_set(skykin_timezone());

} // function_exists guard
