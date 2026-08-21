<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';
if (is_file(__DIR__ . '/skykin_bl_sync.php')) {
	require_once __DIR__ . '/skykin_bl_sync.php';
}

$embed = isset($_GET['embed']) || (string)($_POST['embed'] ?? '') === '1';

if (empty($_SESSION['user_uuid']) || empty($_SESSION['authorized'])) {
	if (isset($_GET['json']) || (string)($_POST['ajax'] ?? '') === '1') {
		header('Content-Type: application/json');
		http_response_code(401);
		echo json_encode(['ok' => false, 'error' => 'login']);
		exit;
	}
	header('Location: /?path=' . urlencode($_SERVER['REQUEST_URI'] ?? '/app/agent_dashboard/blacklist.php'));
	exit;
}

function bl_active_domain() {
	$from = (string)($_POST['domain'] ?? $_GET['domain'] ?? '');
	if ($from === '' && isset($GLOBALS['json_body']) && is_array($GLOBALS['json_body'])) {
		$from = (string)($GLOBALS['json_body']['domain'] ?? '');
	}
	if ($from !== '' && function_exists('skykin_domain_param')) {
		return skykin_domain_param($from);
	}
	$sess = (string)($_SESSION['domain_name'] ?? '');
	if ($sess !== '') {
		return $sess;
	}
	if (function_exists('skykin_domain_param')) {
		return skykin_domain_param(null);
	}
	return $from;
}

function bl_digits($n) {
	$d = preg_replace('/\D+/', '', (string)$n);
	if (strlen($d) >= 12 && substr($d, 0, 3) === '251') {
		$d = substr($d, 3);
	}
	if (strlen($d) === 10 && isset($d[0]) && $d[0] === '0') {
		$d = substr($d, 1);
	}
	return $d;
}

function bl_keys($num) {
	$d = bl_digits($num);
	$keys = [$d, $num];
	if (strlen($d) >= 7) {
		$keys[] = substr($d, -7);
	}
	if (strlen($d) >= 8) {
		$keys[] = substr($d, -8);
	}
	if (strlen($d) >= 9) {
		$keys[] = substr($d, -9);
	}
	if (strlen($d) >= 10) {
		$keys[] = substr($d, -10);
	}
	$keys[] = '251' . $d;
	$keys[] = '0' . $d;
	$out = [];
	foreach ($keys as $k) {
		$k = preg_replace('/\D+/', '', (string)$k);
		if ($k !== '') {
			$out[$k] = $k;
		}
	}
	return array_values($out);
}

function bl_fs_hash($num, $block, $domain = '') {
	if (function_exists('skykin_bl_hash_scope')) {
		skykin_bl_hash_scope((string)$domain, (string)$num, (bool)$block);
		return;
	}
	if (!function_exists('skykin_fs_api')) {
		return;
	}
	$domain = str_replace(['/', ' ', '|', '~'], '', (string)$domain);
	foreach (bl_keys($num) as $k) {
		skykin_fs_api('hash delete/skykin_bl/' . $k);
		if ($block && $domain !== '') {
			skykin_fs_api('hash insert/skykin_bl/' . $domain . '~' . $k . '/1');
		} else {
			skykin_fs_api('hash delete/skykin_bl/' . $domain . '~' . $k);
		}
	}
}

function bl_write_files(PDO $db) {
	list(, $rows) = bl_rows($db);
	$buf = "# domain|digits|display|reason|agent|unix\n";
	foreach ($rows as $r) {
		$buf .= implode('|', [
			str_replace('|', '', (string)($r['domain_name'] ?? '*')),
			str_replace('|', '', (string)($r['digits'] ?? '')),
			str_replace('|', '', (string)($r['display'] ?? $r['digits'] ?? '')),
			str_replace('|', '', (string)($r['reason'] ?? '')),
			str_replace('|', '', (string)($r['agent'] ?? '')),
			(string)((int)($r['ts'] ?? time())),
		]) . "\n";
	}
	$rec = '/var/lib/freeswitch/recordings/skykin_blacklist.txt';
	@file_put_contents($rec, $buf, LOCK_EX);
	@chmod($rec, 0666);
	if (function_exists('skykin_fs_api')) {
		$b64 = base64_encode($buf);
		foreach ([$rec, '/etc/freeswitch/scripts/skykin_blacklist.txt'] as $path) {
			skykin_fs_api('system sh -c "printf %s ' . $b64 . ' | base64 -d > ' . $path . ' && chmod 666 ' . $path . '"');
		}
	}
}

function bl_rows(PDO $db, $domain = '') {
	$out = [];
	try {
		if (function_exists('skykin_bl_ensure_table')) {
			skykin_bl_ensure_table($db);
		} else {
			$db->exec("CREATE TABLE IF NOT EXISTS skykin_blacklist (
				digits text NOT NULL,
				domain_name text NOT NULL DEFAULT '*',
				display text, reason text, agent text, ts bigint)");
		}
		foreach ($db->query('SELECT digits, domain_name, display, reason, agent, ts FROM skykin_blacklist ORDER BY ts DESC') as $r) {
			if ($domain !== '' && strcasecmp((string)($r['domain_name'] ?? ''), $domain) !== 0) {
				continue;
			}
			$out[] = $r;
		}
	} catch (Throwable $e) {
		return [$e->getMessage(), []];
	}
	return ['', $out];
}

$err = '';
$msg = '';
$db = null;
try {
	$db = skykin_pdo_fusionpbx();
} catch (Throwable $e) {
	$err = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {
	$raw_in = (string)file_get_contents('php://input');
	$json_body = json_decode($raw_in, true);
	if (!is_array($json_body)) {
		$json_body = [];
	}
	$action = (string)($_POST['action'] ?? $json_body['action'] ?? '');
	$raw = (string)($_POST['number'] ?? $json_body['number'] ?? '');
	$reason = (string)($_POST['reason'] ?? $json_body['reason'] ?? 'blocked by agent');
	$ajax = (string)($_POST['ajax'] ?? '') === '1' || isset($json_body['number']);
	$domain = bl_active_domain();
	if ($action === '' && strlen(bl_digits($raw)) >= 7) {
		$action = 'add';
	}
	$num = bl_digits($raw);
	if ($action === 'add' && strlen($num) >= 7 && $domain !== '') {
		$st = $db->prepare('INSERT INTO skykin_blacklist (digits, domain_name, display, reason, agent, ts) VALUES (?,?,?,?,?,?) ON CONFLICT (digits, domain_name) DO NOTHING');
		$st->execute([
			$num,
			$domain,
			$raw !== '' ? $raw : $num,
			$reason,
			(string)($_SESSION['username'] ?? ''),
			time(),
		]);
		if (function_exists('skykin_bl_push')) {
			skykin_bl_push($db, $num, true, $domain);
		} else {
			bl_fs_hash($num, true, $domain);
			bl_write_files($db);
		}
		$msg = 'Blocked ' . $num;
	} elseif ($action === 'del' && $num !== '' && $domain !== '') {
		$st = $db->prepare('DELETE FROM skykin_blacklist WHERE digits=? AND domain_name=?');
		$st->execute([$num, $domain]);
		if (function_exists('skykin_bl_push')) {
			skykin_bl_push($db, $num, false, $domain);
		} else {
			bl_fs_hash($num, false, $domain);
			bl_write_files($db);
		}
		$msg = 'Removed ' . $num;
	}
	if ($ajax) {
		list($qerr, $rows) = bl_rows($db, $domain);
		header('Content-Type: application/json');
		echo json_encode(['ok' => true, 'rows' => $rows, 'error' => $qerr, 'msg' => $msg]);
		exit;
	}
	header('Location: blacklist.php' . ($embed ? '?embed=1' : ''));
	exit;
}

$domain = bl_active_domain();
list($qerr, $rows) = $db ? bl_rows($db, $domain) : [$err, []];
if ($qerr && $err === '') {
	$err = $qerr;
}

if (isset($_GET['json'])) {
	header('Content-Type: application/json');
	$want = (string)($_GET['domain'] ?? $_SESSION['domain_name'] ?? '');
	if (function_exists('skykin_domain_param')) {
		$want = skykin_domain_param($want !== '' ? $want : null);
	}
	$out = [];
	foreach ($rows as $r) {
		$have = (string)($r['domain_name'] ?? $r['domain'] ?? '');
		if ($want !== '' && strcasecmp($have, $want) !== 0) {
			continue;
		}
		$out[] = [
			'digits' => (string)($r['digits'] ?? ''),
			'display' => (string)($r['display'] ?? $r['digits'] ?? ''),
			'reason' => (string)($r['reason'] ?? ''),
			'agent' => (string)($r['agent'] ?? ''),
		];
	}
	echo json_encode(['ok' => true, 'rows' => $out, 'error' => $err]);
	exit;
}

$agent_name = htmlspecialchars((string)($_GET['agent'] ?? $_SESSION['username'] ?? 'Agent'));
$domain = htmlspecialchars((string)($_SESSION['domain_name'] ?? ''));
$dash = 'index.php?agent=' . urlencode($_GET['agent'] ?? '') . '&domain=' . urlencode($_GET['domain'] ?? $domain);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Blacklist - SkyKin</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Segoe UI, system-ui, sans-serif; background: #f4f7fb; color: #0f172a; }
  body.embed { background: transparent; }
  body.embed .header, body.embed .side { display: none; }
  body.embed .main { margin: 0; }
  body.embed .card { box-shadow: 0 2px 8px rgba(0,0,0,0.06); max-width: none; }
  .header { position: fixed; top: 0; left: 0; right: 0; height: 64px; background: #0ea5b7; color: #fff; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; z-index: 20; }
  .logo { font-weight: 800; letter-spacing: .3px; }
  .logo span { font-weight: 400; }
  .side { position: fixed; top: 64px; left: 0; width: 240px; bottom: 0; background: #fff; border-right: 1px solid #e2e8f0; padding: 12px 0; }
  .side a { display: block; padding: 10px 20px; color: #334155; text-decoration: none; border-left: 3px solid transparent; }
  .side a:hover { background: #f8fafc; }
  .side a.on { background: #eff6ff; color: #0047AB; border-left-color: #0047AB; font-weight: 700; }
  .main { margin: 84px 24px 40px 264px; }
  .card { background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 2px 10px rgba(15,23,42,.06); max-width: 860px; }
  h1 { margin: 0 0 8px; font-size: 22px; }
  .sub { color: #64748b; margin: 0 0 20px; }
  .row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
  input { padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; min-width: 200px; font-size: 14px; }
  button { padding: 10px 14px; border: 0; border-radius: 8px; background: #0047AB; color: #fff; cursor: pointer; font-size: 14px; }
  .btn-rm { background: #e2e8f0; color: #0f172a; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; padding: 12px 8px; border-bottom: 1px solid #e2e8f0; }
  th { font-size: 12px; color: #64748b; text-transform: uppercase; }
  .num { font-size: 18px; font-weight: 700; }
  .err { background: #fef2f2; color: #b91c1c; padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
  .ok { background: #ecfdf5; color: #047857; padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
</style>
</head>
<body class="<?php echo $embed ? 'embed' : ''; ?>">
<div class="header">
  <div class="logo">SKY<span>KIN</span> Technologies</div>
  <div><?php echo $agent_name; ?> · <?php echo $domain; ?></div>
</div>
<div class="side">
  <a href="<?php echo htmlspecialchars($dash); ?>">Dashboard</a>
  <a class="on" href="blacklist.php">Blacklist</a>
  <a href="<?php echo htmlspecialchars($dash); ?>">Call History</a>
  <a href="/logout.php" style="color:#dc3545">Sign Out</a>
</div>
<div class="main">
  <div class="card">
    <h1>Blocked callers</h1>
    <p class="sub">These numbers will not ring agents. Use Remove to allow them again.</p>
    <?php if ($err): ?><div class="err"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="ok"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
    <form class="row" method="post">
      <input type="hidden" name="action" value="add">
      <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
      <input name="number" required placeholder="Number e.g. 0902925776">
      <input name="reason" placeholder="Reason">
      <button type="submit">Block number</button>
    </form>
    <table>
      <thead><tr><th>Number</th><th>Reason</th><th>Added by</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4" style="color:#64748b">No blocked numbers.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td class="num"><?php echo htmlspecialchars((string)($r['display'] ?: $r['digits'])); ?></td>
          <td><?php echo htmlspecialchars((string)$r['reason']); ?></td>
          <td><?php echo htmlspecialchars((string)$r['agent']); ?></td>
          <td>
            <form method="post" style="margin:0">
              <input type="hidden" name="action" value="del">
              <?php if ($embed): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
              <input type="hidden" name="number" value="<?php echo htmlspecialchars((string)$r['digits']); ?>">
              <button class="btn-rm" type="submit">Remove</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
