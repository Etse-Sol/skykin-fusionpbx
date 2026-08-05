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
 * FusionPBX Postgres from /etc/fusionpbx/config.conf only (no LAN password fallbacks).
 */
function skykin_pdo_fusionpbx(): ?PDO {
	static $db = null;
	static $tried = false;
	if ($tried) {
		return $db;
	}
	$tried = true;

	$h = '127.0.0.1';
	$p = '5432';
	$n = 'fusionpbx';
	$u = 'fusionpbx';
	$pw = '';
	$conf = '/etc/fusionpbx/config.conf';
	if (is_file($conf)) {
		foreach (file($conf) as $ln) {
			$ln = trim($ln);
			if ($ln === '' || $ln[0] === '#' || strpos($ln, '=') === false) {
				continue;
			}
			[$k, $v] = array_map('trim', explode('=', $ln, 2));
			if ($k === 'database.0.host') {
				$h = $v;
			} elseif ($k === 'database.0.port') {
				$p = $v;
			} elseif ($k === 'database.0.name') {
				$n = $v;
			} elseif ($k === 'database.0.username') {
				$u = $v;
			} elseif ($k === 'database.0.password') {
				$pw = $v;
			}
		}
	}

	$dsns = [
		"pgsql:host={$h};port={$p};dbname={$n};connect_timeout=3",
		"pgsql:host=/var/run/postgresql;dbname={$n}",
	];
	foreach ($dsns as $dsn) {
		try {
			$db = new PDO($dsn, $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
			return $db;
		} catch (Exception $ignored) {
		}
	}
	$db = null;
	return null;
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

} // function_exists guard
