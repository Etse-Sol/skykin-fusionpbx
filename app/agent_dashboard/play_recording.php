<?php
/**
 * Recording playback for the SkyKin dashboards.
 *
 * FusionPBX has no /app/recordings/index.php on this install, so every
 * dashboard Play button pointed at a script that does not exist. This serves
 * both softphone uploads and FreeSWITCH recordings from one place.
 *
 * Accepts either:
 *   ?f=<file>&d=<domain>            softphone upload under <domain>/agent
 *   ?filename=<file>&path=<dir>     an absolute FreeSWITCH recording path
 *
 * Range requests are supported so the browser can seek and show duration
 * instead of refetching the whole file.
 */
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';
skykin_require_login(false);

$types = [
	'webm' => 'audio/webm',
	'wav'  => 'audio/wav',
	'mp3'  => 'audio/mpeg',
	'ogg'  => 'audio/ogg',
	'opus' => 'audio/ogg',
	'm4a'  => 'audio/mp4',
];

function skykin_fail(int $code, string $msg): void {
	http_response_code($code);
	header('Content-Type: text/plain; charset=utf-8');
	echo $msg;
	exit;
}

$file = basename((string)($_GET['f'] ?? $_GET['filename'] ?? ''));
if ($file === '' || !preg_match('/^[\w.\-]+$/', $file)) {
	skykin_fail(400, 'Invalid recording name');
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!isset($types[$ext])) {
	skykin_fail(400, 'Unsupported recording type');
}

// Softphone uploads, FreeSWITCH archive paths and legacy flat recordings are all
// resolved by the shared helper, so the Recordings tab and this streamer can
// never disagree about which files exist.
$domain = skykin_domain_param($_GET['d'] ?? null);
$path = skykin_recording_path($file, $domain, (string)($_GET['path'] ?? ''));

if ($path === '') {
	skykin_fail(404, 'Recording not found');
}

$size = filesize($path);
$start = 0;
$end = $size - 1;
$status = 200;

// Honour a single byte range; that is all a media element needs.
$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
	$reqStart = $m[1] === '' ? null : (int)$m[1];
	$reqEnd = $m[2] === '' ? null : (int)$m[2];
	if ($reqStart === null && $reqEnd !== null) {
		$start = max(0, $size - $reqEnd);
	} elseif ($reqStart !== null) {
		$start = $reqStart;
		if ($reqEnd !== null) {
			$end = min($reqEnd, $size - 1);
		}
	}
	if ($start > $end || $start >= $size) {
		header('Content-Range: bytes */' . $size);
		skykin_fail(416, 'Requested range not satisfiable');
	}
	$status = 206;
}

$length = $end - $start + 1;

http_response_code($status);
header('Content-Type: ' . $types[$ext]);
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
if ($status === 206) {
	header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}
header('Content-Disposition: inline; filename="' . $file . '"');
header('Cache-Control: private, max-age=600');
header('X-Content-Type-Options: nosniff');

while (ob_get_level() > 0) {
	ob_end_clean();
}

$fh = fopen($path, 'rb');
if ($fh === false) {
	skykin_fail(500, 'Cannot open recording');
}
fseek($fh, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fh)) {
	$chunk = fread($fh, (int)min(8192, $remaining));
	if ($chunk === false || $chunk === '') {
		break;
	}
	echo $chunk;
	$remaining -= strlen($chunk);
	flush();
}
fclose($fh);
exit;
