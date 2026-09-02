<?php
/**
 * Shared FusionPBX session bootstrap for SkyKin dashboards.
 * Must match resources/require.php cookie rules so tabs keep the same login.
 * Idle logout is enforced in skykin_config.php from the admin setting.
 */
if (session_status() === PHP_SESSION_NONE) {
	$fpbx_session_path = '/var/lib/php/sessions';
	if (is_dir($fpbx_session_path)) {
		session_save_path($fpbx_session_path);
	}
	ini_set('session.gc_maxlifetime', '86400');
	ini_set('session.use_strict_mode', '1');

	$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (isset($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443')
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

	ini_set('session.cookie_httponly', '1');
	ini_set('session.cookie_secure', $https ? '1' : '0');
	ini_set('session.cookie_samesite', 'Lax');

	session_name('PHPSESSID');
	session_set_cookie_params([
		'lifetime' => 0,
		'path' => '/',
		'domain' => '',
		'secure' => $https,
		'httponly' => true,
		'samesite' => 'Lax',
	]);
	session_start();
}

// SkyKin: favicon on all dashboard HTML pages (JSON/API responses pass through unchanged)
if (is_file(__DIR__ . '/../../resources/skykin_favicon.php')) {
	require_once __DIR__ . '/../../resources/skykin_favicon.php';
}
