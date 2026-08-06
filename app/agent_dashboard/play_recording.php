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

const SKYKIN_RECORDINGS_ROOT = '/var/lib/freeswitch/recordings';

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

// Build the candidate locations, most specific first.
$domain = skykin_domain_param($_GET['d'] ?? null);
$candidates = [];
$requested_dir = (string)($_GET['path'] ?? '');
if ($requested_dir !== '') {
	$candidates[] = rtrim($requested_dir, '/') . '/' . $file;
}
$candidates[] = SKYKIN_RECORDINGS_ROOT . '/' . $domain . '/agent/' . $file;
$candidates[] = SKYKIN_RECORDINGS_ROOT . '/' . $domain . '/' . $file;

$path = null;
foreach ($candidates as $candidate) {
	$real = realpath($candidate);
	if ($real === false || !is_file($real)) {
		continue;
	}
	// Never serve anything outside the recordings root.
	if (strpos($real, SKYKIN_RECORDINGS_ROOT . '/') !== 0) {
		continue;
	}
	$path = $real;
	break;
}

// Archive recordings are nested by year/month/day, so search as a last resort.
if ($path === null) {
	$archive = SKYKIN_RECORDINGS_ROOT . '/' . $domain . '/archive';
	if (is_dir($archive)) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archive, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $entry) {
			if ($entry->isFile() && $entry->getFilename() === $file) {
				$path = $entry->getRealPath();
				break;
			}
		}
	}
}

if ($path === null) {
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
