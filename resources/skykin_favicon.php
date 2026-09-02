<?php
/**
 * Inject SkyKin favicon into any FusionPBX HTML page that forgot a <link rel="icon">.
 * Safe for JSON/binary responses — only touches output that contains <html> and <head>.
 */
if (defined('SKYKIN_FAVICON_HOOK')) {
	return;
}
define('SKYKIN_FAVICON_HOOK', true);

function skykin_global_favicon_tags(): string {
	$png = '/app/agent_dashboard/assets/skykin-favicon.png?v=5';
	$ico = '/app/agent_dashboard/assets/favicon.ico?v=5';
	$apple = '/app/agent_dashboard/assets/apple-touch-icon.png?v=5';
	return '<link rel="icon" type="image/png" sizes="32x32" href="' . $png . '">' . "\n"
		. '<link rel="shortcut icon" type="image/png" href="' . $png . '">' . "\n"
		. '<link rel="icon" href="' . $ico . '">' . "\n"
		. '<link rel="apple-touch-icon" sizes="180x180" href="' . $apple . '">' . "\n";
}

ob_start(static function ($html) {
	if (!is_string($html) || strlen($html) < 32) {
		return $html;
	}
	if (stripos($html, '<html') === false || stripos($html, '<head') === false) {
		return $html;
	}
	if (preg_match('/rel\s*=\s*["\']icon["\']/i', $html)) {
		return $html;
	}
	$tags = skykin_global_favicon_tags();
	return preg_replace('/<head([^>]*)>/i', '<head$1>' . $tags, $html, 1);
});
