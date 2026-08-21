<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_uuid']) || empty($_SESSION['authorized'])) {
	http_response_code(401);
	echo json_encode(['ok' => false, 'error' => 'login']);
	exit;
}

$raw_in = (string)file_get_contents('php://input');
$json = json_decode($raw_in, true);
if (!is_array($json)) {
	$json = [];
}

$action = (string)($_POST['action'] ?? $json['action'] ?? 'add');
$raw = (string)($_POST['number'] ?? $json['number'] ?? '');
$reason = (string)($_POST['reason'] ?? $json['reason'] ?? 'blocked by agent');
$domain = (string)($_POST['domain'] ?? $json['domain'] ?? $_GET['domain'] ?? '');
if ($domain === '' && function_exists('skykin_domain_param')) {
	$domain = skykin_domain_param(null);
}
if ($domain === '') {
	$domain = (string)($_SESSION['domain_name'] ?? '');
}

$num = preg_replace('/\D+/', '', $raw);
if (strlen($num) >= 12 && substr($num, 0, 3) === '251') {
	$num = substr($num, 3);
}
if (strlen($num) === 10 && isset($num[0]) && $num[0] === '0') {
	$num = substr($num, 1);
}

if (strlen($num) < 7) {
	echo json_encode(['ok' => false, 'error' => 'Enter a valid phone number', 'raw' => $raw]);
	exit;
}

try {
	$db = skykin_pdo_fusionpbx();
	require_once __DIR__ . '/skykin_bl_sync.php';
	skykin_bl_ensure_table($db);
} catch (Throwable $e) {
	echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
	exit;
}

if ($domain === '') {
	echo json_encode(['ok' => false, 'error' => 'no domain']);
	exit;
}

if ($action === 'del') {
	$st = $db->prepare('DELETE FROM skykin_blacklist WHERE digits=? AND domain_name=?');
	$st->execute([$num, $domain]);
} else {
	$st = $db->prepare('INSERT INTO skykin_blacklist (digits, domain_name, display, reason, agent, ts) VALUES (?,?,?,?,?,?) ON CONFLICT (digits, domain_name) DO NOTHING');
	$st->execute([
		$num,
		$domain,
		$raw !== '' ? $raw : $num,
		$reason,
		(string)($_SESSION['username'] ?? ''),
		time(),
	]);
}

skykin_bl_push($db, $num, $action !== 'del', $domain);

$rows = [];
foreach ($db->query('SELECT digits, domain_name, display, reason, agent, ts FROM skykin_blacklist ORDER BY ts DESC') as $r) {
	if (strcasecmp((string)($r['domain_name'] ?? ''), $domain) !== 0) {
		continue;
	}
	$rows[] = $r;
}

$rec = '/var/lib/freeswitch/recordings/skykin_blacklist.txt';

$out = [];
foreach ($rows as $r) {
	$out[] = [
		'digits' => (string)($r['digits'] ?? ''),
		'display' => (string)($r['display'] ?? $r['digits'] ?? ''),
		'reason' => (string)($r['reason'] ?? ''),
		'agent' => (string)($r['agent'] ?? ''),
	];
}
echo json_encode(['ok' => true, 'digits' => $num, 'domain' => $domain, 'file' => $rec, 'rows' => $out]);
