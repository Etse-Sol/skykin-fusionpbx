<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';

header('Content-Type: application/json');
skykin_session_enforce_idle();
if (empty($_SESSION['user_uuid']) || empty($_SESSION['authorized'])) {
	http_response_code(401);
	echo json_encode(['ok' => false, 'error' => 'Session expired', 'login' => '/logout.php']);
	exit;
}
skykin_session_touch();
echo json_encode([
	'ok' => true,
	'idleTimeoutMinutes' => skykin_idle_timeout_minutes(),
]);
