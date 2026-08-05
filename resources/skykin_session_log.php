<?php
/**
 * Temporary SkyKin session diagnostics.
 * Records why/when a login session ends so unexpected logouts can be traced.
 * Log: /tmp/skykin_session.log
 */
if (!function_exists('skykin_session_log')) {
	function skykin_session_log($event, array $extra = []) {
		$line = [
			'time'    => date('Y-m-d H:i:s'),
			'event'   => $event,
			'sid'     => session_id() ?: '-',
			'user'    => $_SESSION['username'] ?? ($_SESSION['user']['username'] ?? '-'),
			'auth'    => !empty($_SESSION['authorized']) ? '1' : '0',
			'ip'      => $_SERVER['REMOTE_ADDR'] ?? '-',
			'uri'     => $_SERVER['REQUEST_URI'] ?? '-',
			'ref'     => $_SERVER['HTTP_REFERER'] ?? '-',
			'ua'      => substr($_SERVER['HTTP_USER_AGENT'] ?? '-', 0, 80),
		];
		foreach ($extra as $k => $v) {
			$line[$k] = is_scalar($v) ? (string)$v : json_encode($v);
		}
		$parts = [];
		foreach ($line as $k => $v) {
			$parts[] = $k . '=' . str_replace(["\n", "\r", ' '], ['', '', '_'], (string)$v);
		}
		@error_log(implode(' ', $parts) . "\n", 3, '/tmp/skykin_session.log');
	}
}
