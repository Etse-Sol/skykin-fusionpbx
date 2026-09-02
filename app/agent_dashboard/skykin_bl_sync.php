<?php
/** Push dashboard blacklist to FreeSWITCH. Hash keys are domain-scoped (`domain~digits`). */

function skykin_bl_digit_keys(string $num): array {
	$d = preg_replace('/\D+/', '', $num) ?? '';
	if (strlen($d) >= 12 && substr($d, 0, 3) === '251') {
		$d = substr($d, 3);
	}
	if (strlen($d) === 10 && isset($d[0]) && $d[0] === '0') {
		$d = substr($d, 1);
	}
	if ($d === '') {
		return [];
	}
	$keys = [$d, '251' . $d, '0' . $d];
	if (strlen($d) >= 7) {
		$keys[] = substr($d, -7);
	}
	if (strlen($d) >= 8) {
		$keys[] = substr($d, -8);
	}
	if (strlen($d) >= 9) {
		$keys[] = substr($d, -9);
	}
	$out = [];
	foreach ($keys as $k) {
		if ($k !== '') {
			$out[$k] = $k;
		}
	}
	return array_values($out);
}

function skykin_bl_hash_scope(string $domain, string $num, bool $block): void {
	if (!function_exists('skykin_fs_api')) {
		return;
	}
	$domain = str_replace(['/', ' ', '|', '~'], '', $domain);
	foreach (skykin_bl_digit_keys($num) as $k) {
		skykin_fs_api('hash delete/skykin_bl/' . $k);
		$scoped = $domain . '~' . $k;
		if ($block && $domain !== '') {
			skykin_fs_api('hash insert/skykin_bl/' . $scoped . '/1');
		} else {
			skykin_fs_api('hash delete/skykin_bl/' . $scoped);
		}
	}
}

function skykin_bl_ensure_table(PDO $db): void {
	$db->exec("CREATE TABLE IF NOT EXISTS skykin_blacklist (
		digits text NOT NULL,
		domain_name text NOT NULL DEFAULT '*',
		display text, reason text, agent text, ts bigint)");
	try {
		$db->exec('ALTER TABLE skykin_blacklist DROP CONSTRAINT IF EXISTS skykin_blacklist_pkey');
	} catch (Throwable $e) {
	}
	try {
		$db->exec('ALTER TABLE skykin_blacklist ADD PRIMARY KEY (digits, domain_name)');
	} catch (Throwable $e) {
	}
}

function skykin_bl_push(PDO $db, string $num = '', bool $block = true, string $domain = ''): void {
	$rows = [];
	try {
		skykin_bl_ensure_table($db);
		foreach ($db->query('SELECT digits, domain_name, display, reason, agent, ts FROM skykin_blacklist ORDER BY ts DESC') as $r) {
			$rows[] = $r;
		}
	} catch (Throwable $e) {
		$rows = [];
	}
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

	if (!function_exists('skykin_fs_api')) {
		return;
	}
	$b64 = base64_encode($buf);
	skykin_fs_api('system sh -c "printf %s ' . $b64 . ' | base64 -d > /etc/freeswitch/scripts/skykin_blacklist.txt && chmod 666 /etc/freeswitch/scripts/skykin_blacklist.txt"');
	skykin_fs_api('system sh -c "printf %s ' . $b64 . ' | base64 -d > ' . $rec . ' && chmod 666 ' . $rec . '"');

	if ($num !== '') {
		skykin_bl_hash_scope($domain, $num, $block);
	}
	foreach ($rows as $r) {
		$dname = (string)($r['domain_name'] ?? '');
		$digits = (string)($r['digits'] ?? '');
		if ($dname !== '' && $digits !== '') {
			skykin_bl_hash_scope($dname, $digits, true);
		}
	}
}
